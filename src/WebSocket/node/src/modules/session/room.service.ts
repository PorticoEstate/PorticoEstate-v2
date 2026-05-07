import { Injectable } from '@nestjs/common';
import { Server } from 'socket.io';

@Injectable()
export class RoomService {
  private server: Server | null = null;

  setServer(server: Server) {
    this.server = server;
  }

  sessionRoomId(sessionId: string): string {
    return `session_${sessionId}`;
  }

  entityRoomId(entityType: string, entityId: string | number): string {
    return `entity_${entityType}_${entityId}`;
  }

  getRoomSize(roomId: string): number {
    return this.server?.sockets?.adapter?.rooms?.get(roomId)?.size ?? 0;
  }
}
