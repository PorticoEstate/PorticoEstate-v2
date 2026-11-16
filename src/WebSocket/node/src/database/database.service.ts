import {
  Injectable,
  Logger,
  OnModuleInit,
  OnModuleDestroy,
} from '@nestjs/common';
import { Pool, PoolClient, QueryResult, QueryResultRow } from 'pg';
import { PhpConfigService } from '../config/php-config.service';

const RETRY_MIN_MS = 1_000;
const RETRY_MAX_MS = 30_000;

/**
 * Nothing here keeps a "connected" flag to answer from. Every answer comes from a real query on
 * the pool, and the pool opens a new connection for a query when it has none, so a database that
 * was unreachable when the service started is used as soon as it answers again.
 *
 * A flag set once at start-up used to decide that instead: when the database was unreachable at
 * that moment, every request was answered with an empty list until the service was restarted.
 */
@Injectable()
export class DatabaseService implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(DatabaseService.name);
  private pool!: Pool;
  private target = '';
  private retryTimer: ReturnType<typeof setTimeout> | null = null;
  private stopped = false;

  constructor(private readonly configService: PhpConfigService) {}

  async onModuleInit() {
    const dbConfig = this.configService.getDbConfig();
    this.target = `${dbConfig.db_host}/${dbConfig.db_name}`;

    this.pool = new Pool({
      host: dbConfig.db_host,
      port: parseInt(dbConfig.db_port, 10),
      database: dbConfig.db_name,
      user: dbConfig.db_user,
      password: dbConfig.db_pass,
      max: 10,
      idleTimeoutMillis: 30000,
      connectionTimeoutMillis: 5000,
    });

    this.pool.on('error', (err) => {
      this.logger.error('Unexpected pool error', err.message);
    });

    await this.checkConnection(RETRY_MIN_MS);
  }

  async onModuleDestroy() {
    this.stopped = true;
    if (this.retryTimer) clearTimeout(this.retryTimer);
    await this.pool?.end();
  }

  /**
   * Start-up check. It only reports: when the database does not answer, it is asked again after a
   * growing pause until it does, so the log shows when the service can use the database again.
   */
  private async checkConnection(delayMs: number): Promise<void> {
    this.retryTimer = null;
    if (this.stopped) return;
    if (await this.ping()) {
      this.logger.log(`Database connected: ${this.target}`);
      return;
    }
    this.logger.error(`Database connection failed, trying again in ${Math.round(delayMs / 1000)} s`);
    this.retryTimer = setTimeout(
      () => void this.checkConnection(Math.min(delayMs * 2, RETRY_MAX_MS)),
      delayMs,
    );
    this.retryTimer.unref();
  }

  /** True when the database answers a query within timeoutMs. */
  async ping(timeoutMs = 5_000): Promise<boolean> {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const timeout = new Promise<never>((_, reject) => {
      timer = setTimeout(() => reject(new Error(`no answer within ${timeoutMs} ms`)), timeoutMs);
    });
    try {
      await Promise.race([this.pool.query('SELECT 1'), timeout]);
      return true;
    } catch (err: any) {
      this.logger.warn(`Database did not answer: ${err.message}`);
      return false;
    } finally {
      clearTimeout(timer);
    }
  }

  async query<T extends QueryResultRow = any>(sql: string, params?: any[]): Promise<QueryResult<T>> {
    return this.pool.query<T>(sql, params);
  }

  async getClient(): Promise<PoolClient> {
    return this.pool.connect();
  }
}
