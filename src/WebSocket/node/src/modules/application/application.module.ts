import { Module } from '@nestjs/common';
import { ApplicationService } from './application.service';
import { DeliveredApplicationService } from './delivered-application.service';

@Module({
  providers: [ApplicationService, DeliveredApplicationService],
  exports: [ApplicationService, DeliveredApplicationService],
})
export class ApplicationModule {}
