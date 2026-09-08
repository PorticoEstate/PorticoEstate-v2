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

/**
 * The session cookie the bookingfrontend client holds. The client derives the
 * session id it sends in update_session from this same cookie
 * (client/src/service/hooks/use-websocket-session.ts), so the two sides agree
 * by construction rather than by coincidence.
 */
const BOOKING_SESSION_COOKIE = 'bookingfrontendsession';
const STANDARD_SESSION_COOKIE = 'PHPSESSID';

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

    const bookingSessionId = cookies[BOOKING_SESSION_COOKIE] || null;
    const sessionId = bookingSessionId || cookies[STANDARD_SESSION_COOKIE] || null;
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

    // Ownership check. This belongs here, at the binding layer, and nowhere
    // else: every handler that acts on a socket's session (partial-application
    // reads, create_application, delete_partial_application) resolves it through
    // getSession(client.id) and guards only on `!session?.sessionId`. None of
    // them re-checks whose session it is, so a socket that talks its way into
    // the wrong binding is trusted by all of them. Rejecting here closes all of
    // them at once; a check placed in an individual handler would leave the
    // others open.
    //
    // The only thing we can prove about a caller is the cookie it presented on
    // the handshake. If it presented one, it does not get to claim a different
    // session.
    const heldSessionId = this.heldSessionId(session);
    if (heldSessionId && heldSessionId !== newSessionId) {
      this.logger.warn(
        `Rejected session update for ${client.id}: claimed ${newSessionId.substring(0, 8)}... but the handshake presented ${heldSessionId.substring(0, 8)}...`,
      );
      return {
        success: false,
        action: 'rejected',
        message: 'Session ID does not match the session presented on connect',
      };
    }
    // LIMITATION, deliberate and load-bearing: when the handshake presented no
    // cookie at all we have nothing to check the claim against, so we accept it.
    // That case is not decoration — it is the normal cold load, where the socket
    // connects before the session cookie exists and session_id_required is what
    // asks the client to fill the gap. Rejecting it would break the legitimate
    // bind. Its safety therefore rests on the client sending the value it
    // actually holds; the client-side cookie derivation is what makes that true,
    // and this check cannot substitute for it.

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

  /**
   * The session id this caller demonstrably holds, i.e. the one it presented in
   * its handshake cookies. Null means we cannot prove anything about it.
   */
  private heldSessionId(session: SessionData): string | null {
    return (
      session.cookies[BOOKING_SESSION_COOKIE] ||
      session.cookies[STANDARD_SESSION_COOKIE] ||
      null
    );
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
