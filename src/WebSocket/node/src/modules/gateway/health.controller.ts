import {
  Controller,
  Get,
  Post,
  Body,
  Logger,
  HttpCode,
  BadRequestException,
} from '@nestjs/common';
import { RedisService } from '../notification/redis.service';
import { PorticoGateway } from './portico.gateway';

@Controller()
export class HealthController {
  private readonly logger = new Logger(HealthController.name);

  constructor(
    private readonly redisService: RedisService,
    private readonly gateway: PorticoGateway,
  ) {}

  @Get('health')
  getHealth() {
    return {
      status: 'ok',
      clients: this.gateway.getConnectionCount(),
      redis: this.redisService.isConnected(),
      timestamp: new Date().toISOString(),
    };
  }

  @Get('wss/health')
  getWssHealth() {
    return this.getHealth();
  }

  @Post('wss-publish')
  @HttpCode(200)
  publish(@Body() body: any) {
    if (!body || typeof body !== 'object') {
      throw new BadRequestException('Invalid JSON');
    }

    this.gateway.broadcast(body);
    this.logger.debug(`Published message: ${body.type ?? 'unknown'}`);

    return { success: true, message: 'Message broadcasted successfully' };
  }
}
