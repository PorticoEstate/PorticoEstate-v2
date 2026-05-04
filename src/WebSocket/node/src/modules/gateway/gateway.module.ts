import { Module } from '@nestjs/common';
import { ApplicationModule } from '../application/application.module';
import { PorticoGateway } from './portico.gateway';

@Module({
  imports: [ApplicationModule],
  providers: [PorticoGateway],
})
export class GatewayModule {}
