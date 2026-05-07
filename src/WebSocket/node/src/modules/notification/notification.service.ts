import { Injectable, Logger, OnModuleInit } from '@nestjs/common';
import { Server } from 'socket.io';
import { RedisService } from './redis.service';
import { RoomService } from '../session/room.service';

export type PartialApplicationsUpdateHandler = (sessionId: string) => Promise<void>;

@Injectable()
export class NotificationService implements OnModuleInit {
  private readonly logger = new Logger(NotificationService.name);
  private server: Server | null = null;
  private partialAppsHandler: PartialApplicationsUpdateHandler | null = null;

  constructor(
    private readonly redisService: RedisService,
    private readonly roomService: RoomService,
  ) {}

  /**
   * Register a handler for partial application update requests from Redis.
   * Called by the gateway after init so it can do the DB fetch + emit.
   */
  onPartialApplicationsUpdate(handler: PartialApplicationsUpdateHandler) {
    this.partialAppsHandler = handler;
  }

  onModuleInit() {
    this.redisService.onMessage((channel, message) => {
      this.handleRedisMessage(channel, message);
    });
  }

  setServer(server: Server) {
    this.server = server;
  }

  /**
   * Handle messages from Redis pub/sub channels.
   * Mirrors the PHP server.php Redis handler logic exactly.
   */
  private handleRedisMessage(channel: string, rawMessage: string) {
    if (!this.server) return;

    let data: any;
    try {
      data = JSON.parse(rawMessage);
    } catch {
      this.logger.warn(`Invalid JSON on Redis channel ${channel}`);
      return;
    }

    const messageType = data.type ?? 'unknown';

    // --- room_messages channel ---
    if (channel === 'room_messages') {
      if (messageType === 'room_message' && data.roomId) {
        this.server.to(data.roomId).emit('message', data);
        this.logger.debug(`Room message routed to ${data.roomId}`);
      }
      return;
    }

    // --- notifications channel ---
    if (channel === 'notifications') {
      // Entity-targeted (legacy format)
      if (
        data.target === 'entity' &&
        data.entityType &&
        data.entityId
      ) {
        const roomId = this.roomService.entityRoomId(
          data.entityType,
          data.entityId,
        );
        this.server.to(roomId).emit('message', data);
        this.logger.debug(
          `Entity notification -> ${data.entityType}:${data.entityId}`,
        );
        return;
      }

      // Session-targeted
      if (data.target === 'session' && data.sessionId) {
        const roomId = this.roomService.sessionRoomId(data.sessionId);
        this.server.to(roomId).emit('message', data);
        this.logger.debug(
          `Session notification -> ${data.sessionId.substring(0, 8)}...`,
        );
        return;
      }

      // General broadcast
      data.source = 'redis';
      data.received_at = new Date().toISOString();
      this.server.emit('message', data);
      this.logger.debug('Broadcast notification to all clients');
      return;
    }

    // --- session_messages channel ---
    if (channel === 'session_messages') {
      if (messageType === 'session_targeted' && data.sessionId) {
        const roomId = this.roomService.sessionRoomId(data.sessionId);
        const payload = data.data ?? data;
        this.server.to(roomId).emit('message', payload);
        this.logger.debug(
          `Session message -> ${data.sessionId.substring(0, 8)}...`,
        );
        return;
      }

      if (
        messageType === 'update_partial_applications' &&
        data.sessionId
      ) {
        if (this.partialAppsHandler) {
          this.partialAppsHandler(data.sessionId).catch((err) =>
            this.logger.error(`Partial apps update error: ${err.message}`),
          );
        }
        return;
      }

      if (messageType === 'refresh_bookinguser' && data.sessionId) {
        const roomId = this.roomService.sessionRoomId(data.sessionId);
        this.server.to(roomId).emit('message', {
          type: 'refresh_bookinguser',
          message: data.message ?? 'User data has been updated',
          action: data.action ?? 'refresh',
          timestamp: new Date().toISOString(),
        });
        return;
      }

      this.logger.warn(
        `Unhandled session_messages type: ${messageType}`,
      );
    }
  }


}
