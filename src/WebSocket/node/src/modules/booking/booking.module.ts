import { Module } from '@nestjs/common';
import { BookingService } from './booking.service';
import { FreeTimeModule } from '../freetime/freetime.module';

@Module({
  imports: [FreeTimeModule],
  providers: [BookingService],
  exports: [BookingService],
})
export class BookingModule {}
