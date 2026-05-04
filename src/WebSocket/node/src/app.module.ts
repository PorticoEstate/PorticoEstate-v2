import { Module } from '@nestjs/common';
import { ConfigModule } from './config/config.module';
import { DatabaseModule } from './database/database.module';
import { SessionModule } from './modules/session/session.module';
import { NotificationModule } from './modules/notification/notification.module';
import { ApplicationModule } from './modules/application/application.module';
import { GatewayModule } from './modules/gateway/gateway.module';

@Module({
  imports: [
    ConfigModule,
    DatabaseModule,
    SessionModule,
    NotificationModule,
    ApplicationModule,
    GatewayModule,
  ],
})
export class AppModule {}
