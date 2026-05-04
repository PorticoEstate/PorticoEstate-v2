import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { AppModule } from './app.module';
import { Logger } from '@nestjs/common';

async function bootstrap() {
  const logger = new Logger('Bootstrap');
  const port = parseInt(process.env.WS_PORT || '8080', 10);

  const app = await NestFactory.create(AppModule, {
    logger: process.env.WSS_DEBUG_LOG_ENABLED === 'true'
      ? ['log', 'error', 'warn', 'debug', 'verbose']
      : ['log', 'error', 'warn'],
  });

  await app.listen(port);
  logger.log(`WebSocket server listening on port ${port}`);
}

bootstrap();
