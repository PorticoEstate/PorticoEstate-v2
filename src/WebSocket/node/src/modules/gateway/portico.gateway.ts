import {
  WebSocketGateway,
  WebSocketServer,
  OnGatewayInit,
  OnGatewayConnection,
  OnGatewayDisconnect,
  SubscribeMessage,
  MessageBody,
  ConnectedSocket,
} from '@nestjs/websockets';
import { Logger } from '@nestjs/common';
import { Server, Socket } from 'socket.io';
import { SessionService } from '../session/session.service';
import { RoomService } from '../session/room.service';
import { NotificationService } from '../notification/notification.service';
import { ApplicationService } from '../application/application.service';
import { PhpConfigService } from '../../config/php-config.service';

@WebSocketGateway({
  cors: { origin: '*' },
  // Serve on all paths — Apache proxies /wss to us
  namespace: '/',
  // Allow both websocket and polling transports for Socket.IO
  transports: ['websocket', 'polling'],
})
export class PorticoGateway
  implements OnGatewayInit, OnGatewayConnection, OnGatewayDisconnect
{
  private readonly logger = new Logger(PorticoGateway.name);

  @WebSocketServer()
  server!: Server;

  private connectionCount = 0;
  private pingInterval!: ReturnType<typeof setInterval>;
  private entityPingInterval!: ReturnType<typeof setInterval>;
  private cleanupInterval!: ReturnType<typeof setInterval>;
  private fileCheckInterval!: ReturnType<typeof setInterval>;

  constructor(
    private readonly sessionService: SessionService,
    private readonly roomService: RoomService,
    private readonly notificationService: NotificationService,
    private readonly applicationService: ApplicationService,
    private readonly configService: PhpConfigService,
  ) {}

  afterInit(server: Server) {
    this.roomService.setServer(server);
    this.notificationService.setServer(server);

    // Server ping every 60s
    this.pingInterval = setInterval(() => {
      this.notificationService.sendServerPing();
    }, 60_000);

    // Entity room ping every 4min
    this.entityPingInterval = setInterval(() => {
      this.roomService.pingEntityRooms();
    }, 240_000);

    // Cleanup inactive connections every 8min
    this.cleanupInterval = setInterval(() => {
      this.roomService.cleanupInactiveConnections(480_000);
    }, 480_000);

    // File-based notification fallback every 1s
    this.fileCheckInterval = setInterval(() => {
      this.notificationService.checkNotificationFiles();
    }, 1_000);

    // Register handler for partial application update requests from Redis
    this.notificationService.onPartialApplicationsUpdate(
      (sessionId) => this.sendPartialApplicationsUpdate(sessionId),
    );

    this.logger.log('WebSocket gateway initialized');
  }

  async handleConnection(client: Socket) {
    this.connectionCount++;
    const connectTime = Date.now();
    client.data.connectTime = connectTime;

    // Extract session from cookies
    const session = this.sessionService.extractSessionData(client);

    this.logger.log(
      `Connection: ${client.id} (total: ${this.connectionCount}, session: ${session.sessionId ? 'yes' : 'no'})`,
    );

    // If no session, ask client to provide one
    if (session.sessionIdRequired) {
      client.emit('message', {
        type: 'session_id_required',
        message: 'Please provide your session ID via an update_session message',
        code: 'NO_SESSION',
        timestamp: new Date().toISOString(),
      });
    }

    // Join session room if session is available
    if (session.sessionId) {
      const roomId = this.roomService.sessionRoomId(session.sessionId);
      client.join(roomId);
      this.roomService.trackClient(roomId, client.id);

      client.emit('message', {
        type: 'room_joined',
        roomId,
        roomType: 'session',
        message: 'You have been added to a session room',
        roomSize: this.roomService.getRoomSize(roomId),
        timestamp: new Date().toISOString(),
      });

      // Send connection success with environment info
      client.emit('message', {
        type: 'connection_success',
        message: 'Successfully connected to WebSocket server',
        roomId,
        timestamp: new Date().toISOString(),
        rooms: this.getClientRoomsInfo(client.id),
        environment: this.getEnvironmentInfo(),
      });
    }
  }

  handleDisconnect(client: Socket) {
    this.connectionCount--;
    const duration = client.data.connectTime
      ? Math.round((Date.now() - client.data.connectTime) / 1000)
      : 0;

    this.logger.log(
      `Disconnect: ${client.id} (remaining: ${this.connectionCount}, duration: ${duration}s)`,
    );

    this.roomService.untrackClientFromAll(client.id);
    this.sessionService.removeSession(client.id);
  }

  // --- Message handlers (mirror PHP onMessage switch cases) ---

  @SubscribeMessage('message')
  async handleMessage(
    @ConnectedSocket() client: Socket,
    @MessageBody() data: any,
  ) {
    const type = data?.type;
    if (!type) return;

    switch (type) {
      case 'subscribe':
        return this.handleSubscribe(client, data);
      case 'unsubscribe':
        return this.handleUnsubscribe(client, data);
      case 'room_message':
        return this.handleRoomMessage(client, data);
      case 'session_message':
        return this.handleSessionMessage(client, data);
      case 'entity_event':
        return this.handleEntityEvent(client, data);
      case 'update_session':
        return this.handleUpdateSession(client, data);
      case 'room_ping_response':
        return this.handleRoomPingResponse(client, data);
      case 'pong':
        return this.handlePong(client, data);
      case 'update_user_info':
        return this.handleUpdateUserInfo(client, data);
      case 'get_partial_applications':
        return this.handleGetPartialApplications(client);
      case 'ping':
        // Client ping — no action needed
        return;
      default:
        // Forward to all other clients (like PHP's notificationService.processMessage)
        client.broadcast.emit('message', data);
    }
  }

  private handleSubscribe(client: Socket, data: any) {
    const { entityType, entityId } = data;
    if (!entityType || !entityId) return;

    const roomId = this.roomService.entityRoomId(entityType, entityId);
    client.join(roomId);
    this.roomService.trackClient(roomId, client.id);

    this.logger.debug(
      `${client.id} subscribed to ${entityType}:${entityId}`,
    );

    client.emit('message', {
      type: 'room_joined',
      roomId,
      roomType: 'entity',
      entityType,
      entityId,
      message: 'You have been added to an entity room',
      roomSize: this.roomService.getRoomSize(roomId),
      timestamp: new Date().toISOString(),
    });

    client.emit('message', {
      type: 'subscription_confirmation',
      entityType,
      entityId,
      status: 'subscribed',
      timestamp: new Date().toISOString(),
    });
  }

  private handleUnsubscribe(client: Socket, data: any) {
    const { entityType, entityId } = data;
    if (!entityType || !entityId) return;

    const roomId = this.roomService.entityRoomId(entityType, entityId);
    client.leave(roomId);
    this.roomService.untrackClient(roomId, client.id);

    client.emit('message', {
      type: 'subscription_confirmation',
      entityType,
      entityId,
      status: 'unsubscribed',
      timestamp: new Date().toISOString(),
    });
  }

  private handleRoomMessage(client: Socket, data: any) {
    const { roomId } = data;
    if (!roomId) return;

    const clientRooms = this.roomService.getClientRooms(client.id);
    if (clientRooms.includes(roomId)) {
      client.to(roomId).emit('message', data);
    } else {
      client.emit('message', {
        type: 'error',
        message: 'Not authorized to send to this room',
        code: 'ROOM_ACCESS_DENIED',
        timestamp: new Date().toISOString(),
      });
    }
  }

  private handleSessionMessage(client: Socket, data: any) {
    const session = this.sessionService.getSession(client.id);
    if (!session?.sessionId) return;

    const roomId = this.roomService.sessionRoomId(session.sessionId);
    data.type = 'room_message';
    data.roomId = roomId;
    client.to(roomId).emit('message', data);
  }

  private handleEntityEvent(client: Socket, data: any) {
    const { entityType, entityId } = data;
    if (!entityType || !entityId) return;

    const roomId = this.roomService.entityRoomId(entityType, entityId);
    if (this.roomService.roomExists(roomId)) {
      client.to(roomId).emit('message', data);
    }
  }

  private handleUpdateSession(client: Socket, data: any) {
    const { sessionId } = data;
    if (!sessionId || typeof sessionId !== 'string') {
      client.emit('message', {
        type: 'error',
        message: 'Invalid session ID provided',
        code: 'INVALID_SESSION_ID',
        timestamp: new Date().toISOString(),
      });
      return;
    }

    const session = this.sessionService.getSession(client.id);
    const wasRequired = session?.sessionIdRequired ?? false;

    const result = this.sessionService.updateSessionId(
      client,
      sessionId,
      this.roomService,
    );

    if (result.success && result.roomJoined && result.roomId) {
      client.emit('message', {
        type: 'room_joined',
        roomId: result.roomId,
        roomType: 'session',
        message: 'You have been added to a session room',
        roomSize: result.roomSize,
        timestamp: new Date().toISOString(),
      });
    }

    client.emit('message', {
      type: 'session_update_confirmation',
      success: result.success,
      message: wasRequired
        ? 'Session ID set successfully'
        : result.message,
      action: result.action,
      wasRequired,
      sessionId: sessionId.substring(0, 8) + '...',
      timestamp: new Date().toISOString(),
      rooms: this.getClientRoomsInfo(client.id),
      environment: this.getEnvironmentInfo(),
    });
  }

  private handleRoomPingResponse(client: Socket, data: any) {
    if (data.roomId) {
      this.roomService.updateActivity(data.roomId, client.id);
    }
  }

  private handlePong(client: Socket, data: any) {
    // Log round-trip time if available
    if (data.client_timestamp) {
      const rtt = Date.now() - data.client_timestamp;
      this.logger.debug(`Pong from ${client.id}: RTT ${rtt}ms`);
    }
  }

  private handleUpdateUserInfo(client: Socket, data: any) {
    const userId = parseInt(data.userId, 10);
    if (isNaN(userId)) return;

    this.sessionService.updateUserInfo(client.id, userId);

    client.emit('message', {
      type: 'user_info_update_confirmation',
      success: true,
      message: 'User information updated successfully',
      userId,
      timestamp: new Date().toISOString(),
    });
  }

  private async handleGetPartialApplications(client: Socket) {
    const session = this.sessionService.getSession(client.id);

    if (!session?.sessionId) {
      client.emit('message', {
        type: 'partial_applications_response',
        data: { error: true, message: 'No session found', status: 'error' },
        timestamp: new Date().toISOString(),
      });
      return;
    }

    const applications = await this.applicationService.getPartialApplications(
      session.sessionId,
    );

    client.emit('message', {
      type: 'partial_applications_response',
      data: {
        error: false,
        status: 'success',
        applications,
        count: applications.length,
        sessionId: session.sessionId.substring(0, 8) + '...',
      },
      timestamp: new Date().toISOString(),
    });
  }

  /**
   * Push partial applications update to a session room (called from Redis handler).
   */
  private async sendPartialApplicationsUpdate(sessionId: string) {
    const applications =
      await this.applicationService.getPartialApplications(sessionId);

    const roomId = this.roomService.sessionRoomId(sessionId);
    this.server.to(roomId).emit('message', {
      type: 'partial_applications_response',
      data: {
        error: false,
        status: 'success',
        applications,
        count: applications.length,
        sessionId: sessionId.substring(0, 8) + '...',
        source: 'server_push',
        timestamp: new Date().toISOString(),
      },
      timestamp: new Date().toISOString(),
    });
  }

  // --- Helpers ---

  private getClientRoomsInfo(
    clientId: string,
  ): Array<{ id: string; size: number; type: string }> {
    return this.roomService.getClientRooms(clientId).map((roomId) => ({
      id: roomId,
      size: this.roomService.getRoomSize(roomId),
      type: roomId.startsWith('session_') ? 'session' : 'entity',
    }));
  }

  private getEnvironmentInfo() {
    const config = this.configService.getConfig();
    return {
      NEXTJS_HOST: config.hosts.nextjs,
      SLIM_HOST: config.hosts.slim,
      REDIS_HOST: config.redis.host,
      websocket_host: config.hosts.websocket,
    };
  }
}
