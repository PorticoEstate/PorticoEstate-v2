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

  /**
   * Identity-scoped room so a user's notifications reach all their connected
   * tabs/sessions regardless of which page they're on.
   * e.g. user_bb_user_19069736710 or user_phpgw_accounts_7
   */
  userRoomId(userType: string, identifier: string | number): string {
    return `user_${userType}_${identifier}`;
  }

  /**
   * Identity-scoped room so a user's notifications reach all their connected
   * tabs/sessions regardless of which page they're on.
   * e.g. user_bb_user_19069736710 or user_phpgw_accounts_7
   */
  userRoomId(userType: string, identifier: string | number): string {
    return `user_${userType}_${identifier}`;
  }

  getRoomSize(roomId: string): number {
    return this.server?.sockets?.adapter?.rooms?.get(roomId)?.size ?? 0;
  }
}
