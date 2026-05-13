import { Module } from '@nestjs/common';
import { ApplicationModule } from '../application/application.module';
import { FreeTimeModule } from '../freetime/freetime.module';
import { BookingModule } from '../booking/booking.module';
import { PorticoGateway } from './portico.gateway';
import { HealthController } from './health.controller';

@Module({
  imports: [ApplicationModule, FreeTimeModule, BookingModule],
  controllers: [HealthController],
  providers: [PorticoGateway],
})
export class GatewayModule {}
