import { Module } from '@nestjs/common';
import { ApplicationModule } from '../application/application.module';
import { FreeTimeModule } from '../freetime/freetime.module';
import { PorticoGateway } from './portico.gateway';

@Module({
  imports: [ApplicationModule, FreeTimeModule],
  providers: [PorticoGateway],
})
export class GatewayModule {}
