import { Module } from '@nestjs/common';
import { ConfigModule } from './config/config.module';
import { DatabaseModule } from './database/database.module';
import { SessionModule } from './modules/session/session.module';
import { NotificationModule } from './modules/notification/notification.module';
import { ApplicationModule } from './modules/application/application.module';
import { FreeTimeModule } from './modules/freetime/freetime.module';
import { BookingModule } from './modules/booking/booking.module';
import { GatewayModule } from './modules/gateway/gateway.module';

@Module({
  imports: [
    ConfigModule,
    DatabaseModule,
    SessionModule,
    NotificationModule,
    ApplicationModule,
    FreeTimeModule,
    BookingModule,
    GatewayModule,
  ],
})
export class AppModule {}
