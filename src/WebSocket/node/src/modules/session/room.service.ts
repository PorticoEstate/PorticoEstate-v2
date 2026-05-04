import { Injectable, Logger } from '@nestjs/common';
import { Server } from 'socket.io';

@Injectable()
export class RoomService {
  private readonly logger = new Logger(RoomService.name);

  /**
   * Track which clients are in which rooms.
   * Socket.IO manages actual room membership — this map is for
   * metadata like activity timestamps and quick lookups.
   */
  private roomClients = new Map<string, Map<string, { joinedAt: number; lastActivity: number }>>();

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

  trackClient(roomId: string, clientId: string) {
    if (!this.roomClients.has(roomId)) {
      this.roomClients.set(roomId, new Map());
    }
    const now = Date.now();
    this.roomClients.get(roomId)!.set(clientId, {
      joinedAt: now,
      lastActivity: now,
    });
  }

  untrackClient(roomId: string, clientId: string) {
    const room = this.roomClients.get(roomId);
    if (room) {
      room.delete(clientId);
      if (room.size === 0) {
        this.roomClients.delete(roomId);
      }
    }
  }

  untrackClientFromAll(clientId: string) {
    for (const [roomId, clients] of this.roomClients) {
      clients.delete(clientId);
      if (clients.size === 0) {
        this.roomClients.delete(roomId);
      }
    }
  }

  updateActivity(roomId: string, clientId: string) {
    const room = this.roomClients.get(roomId);
    const entry = room?.get(clientId);
    if (entry) {
      entry.lastActivity = Date.now();
    }
  }

  getRoomSize(roomId: string): number {
    return this.roomClients.get(roomId)?.size ?? 0;
  }

  roomExists(roomId: string): boolean {
    return this.roomClients.has(roomId) && this.roomClients.get(roomId)!.size > 0;
  }

  getClientRooms(clientId: string): string[] {
    const rooms: string[] = [];
    for (const [roomId, clients] of this.roomClients) {
      if (clients.has(clientId)) {
        rooms.push(roomId);
      }
    }
    return rooms;
  }

  getAllRooms(): Array<{ id: string; clients: number; isSessionRoom: boolean }> {
    const rooms: Array<{ id: string; clients: number; isSessionRoom: boolean }> = [];
    for (const [roomId, clients] of this.roomClients) {
      rooms.push({
        id: roomId,
        clients: clients.size,
        isSessionRoom: roomId.startsWith('session_'),
      });
    }
    return rooms;
  }

  getEntityRooms(): Array<{ id: string; clients: number }> {
    return this.getAllRooms().filter(
      (r) => !r.isSessionRoom && r.id.startsWith('entity_'),
    );
  }

  /**
   * Remove clients that haven't responded to pings within the threshold.
   */
  cleanupInactiveConnections(thresholdMs: number): {
    connectionsRemoved: number;
    roomsCleaned: string[];
  } {
    const now = Date.now();
    let connectionsRemoved = 0;
    const roomsCleaned: string[] = [];

    for (const [roomId, clients] of this.roomClients) {
      // Skip session rooms — they should persist as long as the connection is alive
      if (roomId.startsWith('session_')) continue;

      for (const [clientId, meta] of clients) {
        if (now - meta.lastActivity > thresholdMs) {
          clients.delete(clientId);
          connectionsRemoved++;

          // Also disconnect from Socket.IO room
          try {
            const sockets = this.server?.sockets?.sockets;
            if (sockets) {
              const socket = sockets.get(clientId);
              if (socket) {
                socket.leave(roomId);
              }
            }
          } catch {
            // Socket may already be disconnected
          }
        }
      }

      if (clients.size === 0) {
        this.roomClients.delete(roomId);
        roomsCleaned.push(roomId);
      }
    }

    if (connectionsRemoved > 0) {
      this.logger.log(
        `Cleanup: removed ${connectionsRemoved} inactive connections from ${roomsCleaned.length} rooms`,
      );
    }

    return { connectionsRemoved, roomsCleaned };
  }

  /**
   * Send a ping to all clients in entity rooms.
   */
  pingEntityRooms() {
    if (!this.server) return;

    const entityRooms = this.getEntityRooms();
    if (entityRooms.length === 0) return;

    this.logger.debug(`Pinging ${entityRooms.length} entity rooms`);

    for (const room of entityRooms) {
      const pingId = `ping_${room.id}_${Date.now()}`;
      this.server.to(room.id).emit('message', {
        type: 'room_ping',
        roomId: room.id,
        pingId,
        timestamp: new Date().toISOString(),
      });
    }
  }
}
