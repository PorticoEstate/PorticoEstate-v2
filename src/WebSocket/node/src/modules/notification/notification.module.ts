import { Module, Global } from '@nestjs/common';
import { RedisService } from './redis.service';
import { NotificationService } from './notification.service';

@Global()
@Module({
  providers: [RedisService, NotificationService],
  exports: [RedisService, NotificationService],
})
export class NotificationModule {}
