import { Module, Global } from '@nestjs/common';
import { SessionService } from './session.service';
import { RoomService } from './room.service';

@Global()
@Module({
  providers: [SessionService, RoomService],
  exports: [SessionService, RoomService],
})
export class SessionModule {}
