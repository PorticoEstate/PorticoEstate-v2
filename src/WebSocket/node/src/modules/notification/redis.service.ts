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
}
