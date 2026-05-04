import { Module } from '@nestjs/common';
import { FreeTimeService } from './freetime.service';

@Module({
  providers: [FreeTimeService],
  exports: [FreeTimeService],
})
export class FreeTimeModule {}
