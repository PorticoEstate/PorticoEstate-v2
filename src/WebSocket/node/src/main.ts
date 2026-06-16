import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { AppModule } from './app.module';
import { Logger } from '@nestjs/common';
import { RedisIoAdapter } from './adapters/redis-io.adapter';

async function bootstrap() {
  const logger = new Logger('Bootstrap');
  const port = parseInt(process.env.WS_PORT || '8080', 10);

  const app = await NestFactory.create(AppModule, {
    logger: process.env.WSS_DEBUG_LOG_ENABLED === 'true'
      ? ['log', 'error', 'warn', 'debug', 'verbose']
      : ['log', 'error', 'warn'],
  });

  try {
    const redisAdapter = new RedisIoAdapter(app);
    await redisAdapter.connectToRedis();
    app.useWebSocketAdapter(redisAdapter);
  } catch (err: any) {
    logger.warn(`Redis adapter unavailable (rooms work locally only): ${err.message}`);
  }

  await app.listen(port);
  logger.log(`WebSocket server listening on port ${port}`);
}

bootstrap();
