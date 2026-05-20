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
import { DeliveredApplicationService } from '../application/delivered-application.service';
import { FreeTimeService } from '../freetime/freetime.service';
import { BookingService } from '../booking/booking.service';
import { PhpConfigService } from '../../config/php-config.service';
import { RedisService } from '../notification/redis.service';

@WebSocketGateway({
  cors: { origin: '*' },
  namespace: '/',
  transports: ['websocket', 'polling'],
  pingInterval: 60_000,
  pingTimeout: 30_000,
})
export class PorticoGateway
  implements OnGatewayInit, OnGatewayConnection, OnGatewayDisconnect
{
  private readonly logger = new Logger(PorticoGateway.name);

  @WebSocketServer()
  server!: Server;

  private connectionCount = 0;

  constructor(
    private readonly sessionService: SessionService,
    private readonly roomService: RoomService,
    private readonly notificationService: NotificationService,
    private readonly applicationService: ApplicationService,
    private readonly deliveredApplicationService: DeliveredApplicationService,
    private readonly freeTimeService: FreeTimeService,
    private readonly bookingService: BookingService,
    private readonly configService: PhpConfigService,
    private readonly redisService: RedisService,
  ) {}

  afterInit(server: Server) {
    this.roomService.setServer(server);
    this.notificationService.setServer(server);

    // Register handler for partial application update requests from Redis
    this.notificationService.onPartialApplicationsUpdate(
      (sessionId) => this.sendPartialApplicationsUpdate(sessionId),
    );

    // Register handler for booking requests from PHP REST fallback
    this.notificationService.onBookingRequest(
      (data) => this.handleRedisBookingRequest(data),
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
        rooms: this.getClientRoomsInfo(client),
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

    // Socket.IO automatically removes client from all rooms on disconnect
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
      case 'pong':
        return; // No-op: Socket.IO's built-in heartbeat handles liveness
      case 'update_user_info':
        return this.handleUpdateUserInfo(client, data);
      case 'get_partial_applications':
        return this.handleGetPartialApplications(client);
      case 'get_delivered_applications':
        return this.handleGetDeliveredApplications(client, data);
      case 'get_application_detail':
        return this.handleGetApplicationDetail(client, data);
      case 'get_free_time':
        return this.handleGetFreeTime(client, data);
      case 'create_simple_application':
        return this.handleCreateSimpleApplication(client, data);
      case 'delete_partial_application':
        return this.handleDeletePartialApplication(client, data);
      case 'ping':
        return; // No-op: Socket.IO handles heartbeat natively
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

    if (client.rooms.has(roomId)) {
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
    client.to(roomId).emit('message', data);
  }

  private handleUpdateSession(client: Socket, data: any) {
    const { sessionId, accountId, ssn } = data;
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

    // Store auth info if provided (accountId + SSN from authenticated sessions)
    if (result.success && (accountId || ssn)) {
      this.sessionService.updateAuthInfo(
        client.id,
        accountId ? Number(accountId) : undefined,
        ssn ? String(ssn) : undefined,
      );
    }

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
      rooms: this.getClientRoomsInfo(client),
      environment: this.getEnvironmentInfo(),
    });
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
   * Handle paginated delivered applications request.
   *
   * Client sends: { type: 'get_delivered_applications', offset?: number, limit?: number, secret?: string }
   * Server responds: { type: 'delivered_applications_response', data: { applications, totalCount, offset, limit, hasMore } }
   *
   * Access: SSN from session auth, org delegates auto-included, or secret for single-app access.
   * The client can call repeatedly with increasing offset to paginate.
   */
  private async handleGetDeliveredApplications(client: Socket, data: any) {
    const session = this.sessionService.getSession(client.id);

    if (!session?.sessionId) {
      client.emit('message', {
        type: 'delivered_applications_response',
        data: { error: true, message: 'No session found' },
        timestamp: new Date().toISOString(),
      });
      return;
    }

    const ssn = session.userInfo?.ssn;
    const secret = data?.secret;
    const offset = Math.max(0, parseInt(data?.offset, 10) || 0);
    const limit = Math.min(100, Math.max(1, parseInt(data?.limit, 10) || 50));

    if (!ssn && !secret) {
      client.emit('message', {
        type: 'delivered_applications_response',
        data: { error: true, message: 'No SSN in session and no secret provided' },
        timestamp: new Date().toISOString(),
      });
      return;
    }

    try {
      const page = await this.deliveredApplicationService.getDeliveredApplications({
        ssn,
        includeOrganizations: true,
        secret,
        offset,
        limit,
      });

      client.emit('message', {
        type: 'delivered_applications_response',
        data: {
          error: false,
          applications: page.applications,
          totalCount: page.totalCount,
          offset: page.offset,
          limit: page.limit,
          hasMore: page.hasMore,
        },
        timestamp: new Date().toISOString(),
      });
    } catch (err: any) {
      this.logger.error(`Error in handleGetDeliveredApplications: ${err.message}`);
      client.emit('message', {
        type: 'delivered_applications_response',
        data: { error: true, message: err.message },
        timestamp: new Date().toISOString(),
      });
    }
  }

  /**
   * Handle single application detail request.
   *
   * Client sends: { type: 'get_application_detail', id: number, secret?: string }
   * Server responds: { type: 'application_detail_response', data: { application, error? } }
   *
   * Also joins the client to an application-specific room for future live updates.
   */
  private async handleGetApplicationDetail(client: Socket, data: any) {
    const session = this.sessionService.getSession(client.id);
    const id = parseInt(data?.id, 10);

    if (!id || isNaN(id)) {
      client.emit('message', {
        type: 'application_detail_response',
        data: { error: true, message: 'Invalid application ID' },
        timestamp: new Date().toISOString(),
      });
      return;
    }

    const ssn = session?.userInfo?.ssn;
    const secret = data?.secret;

    if (!ssn && !secret) {
      client.emit('message', {
        type: 'application_detail_response',
        data: { error: true, message: 'No SSN in session and no secret provided' },
        timestamp: new Date().toISOString(),
      });
      return;
    }

    try {
      const result = await this.deliveredApplicationService.getApplicationById({
        id,
        ssn,
        secret,
      });

      if (result.error || !result.application) {
        client.emit('message', {
          type: 'application_detail_response',
          data: { error: true, message: result.error || 'Application not found', id },
          timestamp: new Date().toISOString(),
        });
        return;
      }

      // Join application room for future live updates
      const appRoomId = this.roomService.entityRoomId('application', id);
      client.join(appRoomId);

      client.emit('message', {
        type: 'application_detail_response',
        data: {
          error: false,
          application: result.application,
          id,
        },
        timestamp: new Date().toISOString(),
      });
    } catch (err: any) {
      this.logger.error(`Error in handleGetApplicationDetail: ${err.message}`);
      client.emit('message', {
        type: 'application_detail_response',
        data: { error: true, message: err.message, id },
        timestamp: new Date().toISOString(),
      });
    }
  }

  /**
   * Push partial applications update to a session room (called from Redis handler).
   */
  private async sendPartialApplicationsUpdate(
    sessionId: string,
    diff?: { added?: number[]; removed?: number[] },
  ) {
    const applications =
      await this.applicationService.getPartialApplications(sessionId);

    const seq = Date.now();
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
        seq,
        diff: diff ?? null,
        timestamp: new Date().toISOString(),
      },
      timestamp: new Date().toISOString(),
    });
  }

  /**
   * Handle a booking request forwarded from PHP REST via Redis.
   * Feeds into the same FIFO queue as WebSocket bookings.
   */
  private async handleRedisBookingRequest(data: any): Promise<void> {
    const { requestId, resourceId, buildingId, from, to, sessionId, ownerId, ssn } = data;

    if (!requestId || !resourceId || !buildingId || !from || !to || !sessionId) {
      this.logger.warn('Invalid booking request from Redis: missing fields');
      if (requestId) {
        await this.redisService.set(
          `booking_result:${requestId}`,
          JSON.stringify({ error: true, message: 'Invalid booking request data' }),
          30,
        );
      }
      return;
    }

    try {
      const result = await this.bookingService.enqueueBooking(
        Number(resourceId),
        Number(buildingId),
        from,
        to,
        sessionId,
        Number(ownerId) || 0,
        ssn || null,
      );

      // Write success to Redis for PHP polling endpoint
      await this.redisService.set(
        `booking_result:${requestId}`,
        JSON.stringify({
          error: false,
          id: result.id,
          status: result.status,
          message: 'Simple application created successfully',
        }),
        30,
      );

      // Send WS notifications (same as handleCreateSimpleApplication)
      this.sendPartialApplicationsUpdate(sessionId, { added: [result.id] })
        .then(() =>
          this.bookingService.publishBookingNotifications(
            sessionId,
            Number(buildingId),
            Number(resourceId),
            from,
            to,
            result.id,
          ),
        )
        .catch((err) =>
          this.logger.error(`Redis booking notification error: ${err.message}`),
        );
    } catch (err: any) {
      this.logger.warn(`Redis booking request failed: ${err.message}`);
      await this.redisService.set(
        `booking_result:${requestId}`,
        JSON.stringify({
          error: true,
          message: err.message,
          translationKey: err.translationKey ?? null,
        }),
        30,
      );
    }
  }

  private async handleCreateSimpleApplication(client: Socket, data: any) {

    const { resourceId, buildingId, from, to, ownerId: clientOwnerId, requestId } = data;

    if (!resourceId || !buildingId || !from || !to) {
      client.emit('message', {
        type: 'create_application_response',
        data: { error: true, message: 'resourceId, buildingId, from, and to are required' },
        ...(requestId && { requestId }),
        timestamp: new Date().toISOString(),
      });
      return;
    }

    const session = this.sessionService.getSession(client.id);
    if (!session?.sessionId) {
      client.emit('message', {
        type: 'create_application_response',
        data: { error: true, message: 'No session' },
        ...(requestId && { requestId }),
        timestamp: new Date().toISOString(),
      });
      return;
    }

    // Convert timestamps (ms) to date strings if needed
    let fromStr = String(from);
    let toStr = String(to);
    if (/^\d{13,}$/.test(fromStr)) {
      const d = new Date(parseInt(fromStr, 10));
      fromStr = `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())} ${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}`;
    }
    if (/^\d{13,}$/.test(toStr)) {
      const d = new Date(parseInt(toStr, 10));
      toStr = `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())} ${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}`;
    }

    try {
      const result = await this.bookingService.enqueueBooking(
        Number(resourceId),
        Number(buildingId),
        fromStr,
        toStr,
        session.sessionId,
        session.userInfo?.accountId ?? 0,
        session.userInfo?.ssn ?? null,
      );

      // Send success response immediately
      client.emit('message', {
        type: 'create_application_response',
        data: {
          error: false,
          id: result.id,
          status: result.status,
          message: 'Simple application created successfully',
        },
        ...(requestId && { requestId }),
        timestamp: new Date().toISOString(),
      });

      // Send partial apps update first (must arrive before timeslot update
      // so the client can match overlap_event.id to the shopping cart)
      this.sendPartialApplicationsUpdate(session.sessionId!, { added: [result.id] })
        .then(() =>
          this.bookingService.publishBookingNotifications(
            session.sessionId!,
            Number(buildingId),
            Number(resourceId),
            fromStr,
            toStr,
            result.id,
          ),
        )
        .catch((err) =>
          this.logger.error(`Booking notification error: ${err.message}`),
        );
    } catch (err: any) {
      this.logger.warn(`Booking failed: ${err.message}`);

      // Send error response — client shows this as a toast
      client.emit('message', {
        type: 'create_application_response',
        data: {
          error: true,
          message: err.message,
        },
        ...(requestId && { requestId }),
        timestamp: new Date().toISOString(),
      });

      // Also send as server_message for toast display
      const roomId = this.roomService.sessionRoomId(session.sessionId);
      const isTranslatable = err.translationKey != null;
      this.server.to(roomId).emit('message', {
        type: 'server_message',
        action: 'new',
        messages: [
          {
            id: `err_${Date.now()}`,
            type: 'error',
            text: isTranslatable ? err.translationKey : err.message,
            translatable: isTranslatable,
          },
        ],
        timestamp: new Date().toISOString(),
      });
    }
  }

  private async handleDeletePartialApplication(client: Socket, data: any) {
    const { applicationId, requestId } = data;

    if (!applicationId) {
      client.emit('message', {
        type: 'delete_application_response',
        data: { error: true, message: 'applicationId is required' },
        ...(requestId && { requestId }),
        timestamp: new Date().toISOString(),
      });
      return;
    }

    const session = this.sessionService.getSession(client.id);
    if (!session?.sessionId) {
      client.emit('message', {
        type: 'delete_application_response',
        data: { error: true, message: 'No session' },
        ...(requestId && { requestId }),
        timestamp: new Date().toISOString(),
      });
      return;
    }

    try {
      const result = await this.bookingService.deletePartialApplication(
        Number(applicationId),
        session.sessionId,
      );

      client.emit('message', {
        type: 'delete_application_response',
        data: {
          error: false,
          id: applicationId,
          message: 'Application deleted successfully',
        },
        ...(requestId && { requestId }),
        timestamp: new Date().toISOString(),
      });

      // Push updated partial applications (with diff) then publish timeslot notifications
      this.sendPartialApplicationsUpdate(session.sessionId!, { removed: [Number(applicationId)] })
        .then(() => {
          if (result.buildingId && result.resourceIds.length > 0 && result.dates.length > 0) {
            return this.bookingService.publishDeletionNotifications(
              result.buildingId,
              result.resourceIds,
              result.dates,
              Number(applicationId),
            );
          }
        })
        .catch((err) =>
          this.logger.error(`Deletion notification error: ${err.message}`),
        );
    } catch (err: any) {
      this.logger.warn(`Delete failed: ${err.message}`);

      client.emit('message', {
        type: 'delete_application_response',
        data: {
          error: true,
          message: err.message,
        },
        ...(requestId && { requestId }),
        timestamp: new Date().toISOString(),
      });
    }
  }

  private async handleGetFreeTime(client: Socket, data: any) {
    const { buildingId, resourceId, startDate, endDate, detailedOverlap, stopOnEndDate } = data;

    if (!buildingId || !startDate || !endDate) {
      client.emit('message', {
        type: 'free_time_response',
        data: { error: true, message: 'buildingId, startDate and endDate are required' },
        timestamp: new Date().toISOString(),
      });
      return;
    }

    const session = this.sessionService.getSession(client.id);

    try {
      const result = await this.freeTimeService.getFreeTime(
        Number(buildingId),
        resourceId ? Number(resourceId) : null,
        startDate,
        endDate,
        session?.sessionId || null,
        detailedOverlap ?? false,
        stopOnEndDate ?? false,
      );

      client.emit('message', {
        type: 'free_time_response',
        data: {
          error: false,
          status: 'success',
          result,
          buildingId: Number(buildingId),
          startDate,
          endDate,
        },
        timestamp: new Date().toISOString(),
      });
    } catch (err: any) {
      this.logger.error(`FreeTime error: ${err.message}`);
      client.emit('message', {
        type: 'free_time_response',
        data: { error: true, message: err.message },
        timestamp: new Date().toISOString(),
      });
    }
  }

  // --- Helpers ---

  private getClientRoomsInfo(
    client: Socket,
  ): Array<{ id: string; size: number; type: string }> {
    return Array.from(client.rooms)
      .filter((roomId) => roomId !== client.id) // exclude the default self-room
      .map((roomId) => ({
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

function pad2(n: number): string {
  return n < 10 ? '0' + n : String(n);
}
