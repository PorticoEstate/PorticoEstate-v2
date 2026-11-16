import {
  Controller,
  Get,
  NotFoundException,
  Req,
  ServiceUnavailableException,
} from '@nestjs/common';
import { DatabaseService } from '../database/database.service';

/** The parts of the HTTP request the check reads. */
interface HealthRequest {
  url?: string;
  socket: { remoteAddress?: string };
}

function isLoopback(address: string | undefined): boolean {
  if (!address) return false;
  return address === '::1' || /^(::ffff:)?127\./.test(address);
}

/**
 * Container health: 200 while the service can query its database, 503 while it cannot. The image
 * HEALTHCHECK calls it (Dockerfile.websocket), so a service that answers websocket handshakes but
 * cannot read anything is reported unhealthy.
 *
 * Only a caller on this host gets an answer; anyone else gets the 404 of a route that does not
 * exist. Apache passes every /wss request on to this service, so the check is on the address of
 * the connection itself. Forwarded-for headers are written by whoever sends the request and are
 * not read.
 */
@Controller('health')
export class HealthController {
  constructor(private readonly db: DatabaseService) {}

  @Get()
  async check(@Req() req: HealthRequest): Promise<{ status: 'ok' }> {
    if (!isLoopback(req.socket.remoteAddress)) {
      throw new NotFoundException(`Cannot GET ${req.url ?? '/health'}`);
    }
    if (!(await this.db.ping(2_000))) {
      throw new ServiceUnavailableException('Database unavailable');
    }
    return { status: 'ok' };
  }
}
