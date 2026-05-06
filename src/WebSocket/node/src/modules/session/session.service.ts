import { Injectable, Logger } from '@nestjs/common';
import { Socket } from 'socket.io';
import { RoomService } from './room.service';

export interface SessionData {
  sessionId: string | null;
  bookingSessionId: string | null;
  cookies: Record<string, string>;
  userAgent: string;
  userInfo: UserInfo | null;
  sessionIdRequired: boolean;
}

export interface UserInfo {
  sessionFound: boolean;
  sessionId: string;
  sessionType: 'booking' | 'standard';
  userId?: number;
  accountId?: number;
  ssn?: string;
}

@Injectable()
export class SessionService {
  private readonly logger = new Logger(SessionService.name);

  /** Map from socket.id to session data */
  private sessions = new Map<string, SessionData>();

  extractSessionData(client: Socket): SessionData {
    const cookies = this.parseCookies(
      client.handshake.headers.cookie || '',
    );

    const bookingSessionId = cookies['bookingfrontendsession'] || null;
    const sessionId = bookingSessionId || cookies['PHPSESSID'] || null;
    const userAgent =
      (client.handshake.headers['user-agent'] as string) || 'unknown';

    const userInfo: UserInfo | null = sessionId
      ? {
          sessionFound: true,
          sessionId: sessionId.substring(0, 8) + '****',
          sessionType: bookingSessionId ? 'booking' : 'standard',
        }
      : null;

    const data: SessionData = {
      sessionId,
      bookingSessionId,
      cookies,
      userAgent,
      userInfo,
      sessionIdRequired: !sessionId,
    };

    this.sessions.set(client.id, data);

    this.logger.log(
      `Session extracted for ${client.id}: session=${sessionId ? sessionId.substring(0, 8) + '...' : 'none'}, type=${bookingSessionId ? 'booking' : 'standard'}`,
    );

    return data;
  }

  getSession(clientId: string): SessionData | undefined {
    return this.sessions.get(clientId);
  }

  updateSessionId(
    client: Socket,
    newSessionId: string,
    roomService: RoomService,
  ): {
    success: boolean;
    action: string;
    message: string;
    roomId?: string;
    roomJoined?: boolean;
    roomSize?: number;
  } {
    const session = this.sessions.get(client.id);
    if (!session) {
      return { success: false, action: 'error', message: 'No session found' };
    }

    if (session.sessionId === newSessionId) {
      return {
        success: true,
        action: 'none',
        message: 'Session ID is unchanged',
      };
    }

    const oldSessionId = session.sessionId;

    // Leave old session room
    if (oldSessionId) {
      const oldRoomId = roomService.sessionRoomId(oldSessionId);
      client.leave(oldRoomId);
      roomService.untrackClient(oldRoomId, client.id);
    }

    // Update session data
    session.sessionId = newSessionId;
    session.bookingSessionId = newSessionId;
    session.sessionIdRequired = false;
    session.userInfo = {
      sessionFound: true,
      sessionId: newSessionId.substring(0, 8) + '****',
      sessionType: 'booking',
    };

    // Join new session room
    const newRoomId = roomService.sessionRoomId(newSessionId);
    client.join(newRoomId);
    roomService.trackClient(newRoomId, client.id);

    this.logger.log(
      `Session updated for ${client.id}: ${oldSessionId ? oldSessionId.substring(0, 8) + '...' : 'none'} -> ${newSessionId.substring(0, 8)}...`,
    );

    return {
      success: true,
      action: oldSessionId ? 'updated' : 'set',
      message: oldSessionId ? 'Session ID updated' : 'Session ID set',
      roomId: newRoomId,
      roomJoined: true,
      roomSize: roomService.getRoomSize(newRoomId),
    };
  }

  updateUserInfo(clientId: string, userId: number): boolean {
    const session = this.sessions.get(clientId);
    if (!session || !session.userInfo) return false;
    session.userInfo.userId = userId;
    return true;
  }

  updateAuthInfo(clientId: string, accountId?: number, ssn?: string): boolean {
    const session = this.sessions.get(clientId);
    if (!session || !session.userInfo) return false;
    if (accountId !== undefined) session.userInfo.accountId = accountId;
    if (ssn !== undefined) session.userInfo.ssn = ssn;
    return true;
  }

  removeSession(clientId: string) {
    this.sessions.delete(clientId);
  }

  private parseCookies(cookieHeader: string): Record<string, string> {
    const cookies: Record<string, string> = {};
    if (!cookieHeader) return cookies;

    const pairs = cookieHeader.split(';');
    for (const pair of pairs) {
      const eqIndex = pair.indexOf('=');
      if (eqIndex === -1) continue;
      const key = pair.substring(0, eqIndex).trim();
      const value = decodeURIComponent(pair.substring(eqIndex + 1).trim());
      cookies[key] = value;
    }
    return cookies;
  }
}
