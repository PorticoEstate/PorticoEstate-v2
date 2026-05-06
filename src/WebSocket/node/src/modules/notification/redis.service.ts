import {
  Injectable,
  Logger,
  OnModuleInit,
  OnModuleDestroy,
} from '@nestjs/common';
import Redis from 'ioredis';
import { PhpConfigService } from '../../config/php-config.service';

export type RedisMessageHandler = (channel: string, message: string) => void;

@Injectable()
export class RedisService implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(RedisService.name);

  private subscriber: Redis | null = null;
  private publisher: Redis | null = null;
  private connected = false;
  private handlers: RedisMessageHandler[] = [];

  constructor(private readonly configService: PhpConfigService) {}

  async onModuleInit() {
    const { host, port } = this.configService.getConfig().redis;

    try {
      this.publisher = new Redis({ host, port, lazyConnect: true });
      this.subscriber = new Redis({ host, port, lazyConnect: true });

      await this.publisher.connect();
      await this.subscriber.connect();

      this.subscriber.on('message', (channel, message) => {
        for (const handler of this.handlers) {
          try {
            handler(channel, message);
          } catch (err: any) {
            this.logger.error(
              `Redis message handler error: ${err.message}`,
            );
          }
        }
      });

      // Subscribe to the same channels as the PHP server
      await this.subscriber.subscribe(
        'notifications',
        'session_messages',
        'room_messages',
      );

      this.connected = true;
      this.logger.log(`Redis connected: ${host}:${port}`);
    } catch (err: any) {
      this.logger.error(`Redis connection failed: ${err.message}`);
    }
  }

  async onModuleDestroy() {
    await this.subscriber?.quit();
    await this.publisher?.quit();
  }

  isConnected(): boolean {
    return this.connected;
  }

  onMessage(handler: RedisMessageHandler) {
    this.handlers.push(handler);
  }

  async publish(channel: string, data: any): Promise<boolean> {
    if (!this.publisher || !this.connected) return false;
    try {
      const payload = typeof data === 'string' ? data : JSON.stringify(data);
      await this.publisher.publish(channel, payload);
      return true;
    } catch (err: any) {
      this.logger.error(`Redis publish error: ${err.message}`);
      return false;
    }
  }

  /**
   * Atomic SETNX with TTL — same as PHP Cache::acquire_atomic_lock.
   */
  async setnx(key: string, value: string, ttlSeconds: number): Promise<boolean> {
    if (!this.publisher || !this.connected) return false;
    try {
      const result = await this.publisher.set(key, value, 'EX', ttlSeconds, 'NX');
      return result === 'OK';
    } catch (err: any) {
      this.logger.error(`Redis SETNX error: ${err.message}`);
      return false;
    }
  }

  /**
   * Atomic lock release — same Lua script as PHP RedisCache::release_lock.
   * Only deletes the key if the value matches (prevents releasing another session's lock).
   */
  async releaseLock(key: string, value: string): Promise<boolean> {
    if (!this.publisher || !this.connected) return false;
    try {
      const result = await this.publisher.eval(
        `if redis.call('GET', KEYS[1]) == ARGV[1] then
           return redis.call('DEL', KEYS[1])
         else
           return 0
         end`,
        1,
        key,
        value,
      );
      return (result as number) > 0;
    } catch (err: any) {
      this.logger.error(`Redis release lock error: ${err.message}`);
      return false;
    }
  }
}
