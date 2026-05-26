import {
  Injectable,
  Logger,
  OnModuleInit,
  OnModuleDestroy,
} from '@nestjs/common';
import { Pool, PoolClient, QueryResult, QueryResultRow } from 'pg';
import { PhpConfigService } from '../config/php-config.service';

@Injectable()
export class DatabaseService implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(DatabaseService.name);
  private pool!: Pool;
  private connected = false;

  constructor(private readonly configService: PhpConfigService) {}

  async onModuleInit() {
    const dbConfig = this.configService.getDbConfig();

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

    try {
      const client = await this.pool.connect();
      await client.query('SELECT 1');
      client.release();
      this.connected = true;
      this.logger.log(
        `Database connected: ${dbConfig.db_host}/${dbConfig.db_name}`,
      );
    } catch (err: any) {
      this.logger.error('Database connection failed', err.message);
    }
  }

  async onModuleDestroy() {
    await this.pool?.end();
  }

  isConnected(): boolean {
    return this.connected;
  }

  async query<T extends QueryResultRow = any>(sql: string, params?: any[]): Promise<QueryResult<T>> {
    return this.pool.query<T>(sql, params);
  }

  async getClient(): Promise<PoolClient> {
    return this.pool.connect();
  }
}
