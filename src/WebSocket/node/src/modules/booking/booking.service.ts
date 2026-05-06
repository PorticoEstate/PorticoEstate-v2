import { Injectable, Logger, OnModuleInit } from '@nestjs/common';
import { createHash, randomBytes } from 'crypto';
import * as fs from 'fs';
import { DatabaseService } from '../../database/database.service';
import { RedisService } from '../notification/redis.service';
import { FreeTimeService } from '../freetime/freetime.service';

/**
 * Source file verification.
 * This Node booking service was ported from these PHP files at the commit/MD5s below.
 * If any source file has changed, the WS booking endpoint is DISABLED and clients
 * must fall back to the PHP REST endpoint.
 *
 * Ported from commit: a488d135 (timeslots better detection if you own them)
 */
const PHP_SOURCE_CHECKSUMS: Record<string, string> = {
  '/var/www/html/src/modules/bookingfrontend/services/applications/ApplicationService.php':
    '19543bdc20cb7e8223d53708d8684724',
  '/var/www/html/src/modules/bookingfrontend/repositories/ApplicationRepository.php':
    '33c4bb700ca06e5ee6a078a0aabc344e',
  '/var/www/html/src/modules/bookingfrontend/repositories/ArticleRepository.php':
    'f865e0ea6667e68224b4be81efff56dd',
};

export class TranslatableError extends Error {
  translationKey: string;
  constructor(message: string, translationKey: string) {
    super(message);
    this.translationKey = translationKey;
  }
}

interface BookingResult {
  id: number;
  status: string;
}

interface OverlapEvent {
  from_: string;
  to_: string;
  resources: number[];
  type: string;
  id: number;
  status: string | null;
}

interface OverlapEvent_detail {
  id: number | null;
  type: string;
  status: string | null;
  from: string;
  to: string;
}

interface OverlapResult {
  status: number;
  reason: string | null;
  type: string | null;
  event: OverlapEvent_detail | null;
}

/**
 * Port of PHP ApplicationService::createSimpleBooking.
 * 1:1 line-by-line match of the PHP flow:
 *   1. Redis atomic lock
 *   2. BEGIN transaction
 *   3. SELECT FOR UPDATE overlap check (atomic DB-level)
 *   4. Verify resource supports simple booking
 *   5. Check availability (check_if_resurce_is_taken port)
 *   6. Booking limits (if SSN available)
 *   7. Create block
 *   8. Get building name
 *   9. Insert application + resources + dates
 *  10. Auto-assign mandatory articles
 *  11. Update id_string
 *  12. COMMIT
 *  13. Release lock
 */
@Injectable()
export class BookingService implements OnModuleInit {
  private readonly logger = new Logger(BookingService.name);
  private installId: string | null = null;
  private sourceVerified = false;

  constructor(
    private readonly db: DatabaseService,
    private readonly redisService: RedisService,
    private readonly freeTimeService: FreeTimeService,
  ) {}

  async onModuleInit() {
    this.sourceVerified = this.verifySourceChecksums();

    try {
      const { rows } = await this.db.query(
        "SELECT config_value FROM phpgw_config WHERE config_name = 'install_id' LIMIT 1",
      );
      this.installId = rows[0]?.config_value || null;
      if (this.installId) {
        this.logger.log('Loaded install_id for Redis lock compatibility');
      }
    } catch (err: any) {
      this.logger.error(`Failed to load install_id: ${err.message}`);
    }
  }

  isEnabled(): boolean {
    return this.sourceVerified;
  }

  private verifySourceChecksums(): boolean {
    for (const [filePath, expectedMd5] of Object.entries(PHP_SOURCE_CHECKSUMS)) {
      try {
        if (!fs.existsSync(filePath)) {
          this.logger.warn(`Source verification: ${filePath} not found — booking WS DISABLED`);
          return false;
        }
        const content = fs.readFileSync(filePath);
        const actualMd5 = createHash('md5').update(content).digest('hex');
        if (actualMd5 !== expectedMd5) {
          this.logger.warn(
            `Source verification FAILED: ${filePath} changed (expected ${expectedMd5}, got ${actualMd5}) — booking WS DISABLED`,
          );
          return false;
        }
      } catch (err: any) {
        this.logger.warn(`Source verification error: ${err.message} — booking WS DISABLED`);
        return false;
      }
    }
    this.logger.log('Source verification passed — booking WS endpoint ENABLED');
    return true;
  }

  /**
   * 1:1 port of PHP ApplicationService::createSimpleBooking
   * (src/modules/bookingfrontend/services/applications/ApplicationService.php:1277)
   */
  async createSimpleBooking(
    resourceId: number,
    buildingId: number,
    from: string,
    to: string,
    sessionId: string,
    ownerId: number,
    ssn: string | null,
  ): Promise<BookingResult> {
    const lockKey = `booking_lock_${resourceId}_${from}_${to}`;
    const lockTtl = 30;
    let lockAcquired = false;
    let client: any = null;
    let startedTransaction = false;

    try {
      // 1. ATOMIC LOCK ACQUISITION - Use Redis SETNX for true atomicity
      // PHP: Cache::acquire_atomic_lock('booking', $lockKey, $sessionId, $lockTtl)
      lockAcquired = await this.acquireLock(lockKey, sessionId, lockTtl);

      if (!lockAcquired) {
        throw new TranslatableError('Resource is already being booked by another user', 'resource_already_being_booked');
      }

      try {
        // 2. Start database transaction for atomic booking operation
        client = await this.db.getClient();
        await client.query('BEGIN');
        startedTransaction = true;

        // 3. ATOMIC OVERLAP CHECK WITH ROW LOCKING
        // PHP: SELECT FOR UPDATE to lock overlapping rows within the transaction
        const overlapResult = await client.query(
          `SELECT a.id, a.status, ad.from_, ad.to_
           FROM bb_application a
           JOIN bb_application_resource ar ON a.id = ar.application_id
           JOIN bb_application_date ad ON a.id = ad.application_id
           WHERE ar.resource_id = $1
           AND a.status NOT IN ('REJECTED')
           AND a.active = 1
           AND ((ad.from_ < $2 AND ad.to_ > $3)
             AND NOT (ad.from_ = $2 OR ad.to_ = $3))
           FOR UPDATE
           LIMIT 1`,
          [resourceId, to, from],
        );

        if (overlapResult.rows.length > 0) {
          // PHP: release atomic lock, then clear blocks, then throw
          await this.releaseLock(lockKey, sessionId).catch(() => {});
          lockAcquired = false;
          await this.clearBlocks(client, sessionId, resourceId, from, to).catch(() => {});
          throw new TranslatableError('Resource is already booked for this time slot', 'resource_already_booked');
        }
      } catch (err) {
        // PHP: release atomic lock on any DB check exception, clear blocks, re-throw
        await this.releaseLock(lockKey, sessionId).catch(() => {});
        lockAcquired = false;
        await this.clearBlocks(client, sessionId, resourceId, from, to).catch(() => {});
        throw err;
      }

      // 4. Check if resource supports simple booking
      // PHP: $resource = $this->getSimpleBookingResource($resourceId)
      const resourceResult = await client.query(
        `SELECT r.*, br.building_id
         FROM bb_resource r
         JOIN bb_building_resource br ON r.id = br.resource_id
         WHERE r.id = $1 AND r.active = 1 AND r.simple_booking = 1`,
        [resourceId],
      );
      if (resourceResult.rows.length === 0) {
        throw new Error('Resource does not support simple booking');
      }
      const resource = resourceResult.rows[0];

      // 5. Check availability using checkSimpleBookingAvailability
      // PHP: $availability = $this->checkSimpleBookingAvailability($resourceId, $from, $to, $sessionId)
      const availability = await this.checkSimpleBookingAvailability(
        client, resourceId, from, to, sessionId, resource, ssn,
      );
      if (!availability.available) {
        const message = availability.message ?? 'Timeslot is not available';
        const reason = availability.overlap_reason ?? null;
        const type = availability.overlap_type ?? null;
        if (reason && type) {
          throw new Error(`${message}: ${reason} (${type})`);
        } else {
          throw new Error(message);
        }
      }

      // 6. Booking limits — only if SSN available
      // PHP: $ssn = $this->userHelper->ssn;
      // PHP: if ($ssn && $resource['booking_limit_number'] > 0 && $resource['booking_limit_number_horizont'] > 0)
      if (
        ssn &&
        resource.booking_limit_number > 0 &&
        resource.booking_limit_number_horizont > 0
      ) {
        // PHP: $currentBookings = $this->getUserBookingCount($resourceId, $ssn, $resource['booking_limit_number_horizont'])
        const countResult = await client.query(
          `SELECT COUNT(*) as count FROM bb_application a
           JOIN bb_application_resource ar ON a.id = ar.application_id
           WHERE ar.resource_id = $1
           AND a.customer_ssn = $2
           AND a.created >= NOW() - (INTERVAL '1 day' * $3)
           AND a.status != 'REJECTED'
           AND a.active = 1`,
          [resourceId, ssn, resource.booking_limit_number_horizont],
        );
        const currentBookings = parseInt(countResult.rows[0].count, 10);

        if (currentBookings >= resource.booking_limit_number) {
          throw new Error(
            `Quantity limit (${currentBookings}) exceeded for ${resource.name}: ` +
            `maximum ${resource.booking_limit_number} times within a period of ` +
            `${resource.booking_limit_number_horizont} days`,
          );
        }
      }

      // 7. Create block
      // PHP: if (!$this->applicationRepository->createBlock($sessionId, $resourceId, $from, $to))
      const blockCreated = await this.createBlock(client, sessionId, resourceId, from, to);
      if (!blockCreated) {
        throw new Error('Failed to create block for timeslot');
      }

      // 8. Get building name
      // PHP: SELECT name FROM bb_building WHERE id = :id
      const buildingResult = await client.query(
        'SELECT name FROM bb_building WHERE id = $1',
        [buildingId],
      );
      if (buildingResult.rows.length === 0) {
        throw new Error('Building not found');
      }
      const buildingName = buildingResult.rows[0].name;

      // 9. Insert application (matches PHP insertApplication fields exactly)
      // PHP: $this->savePartialApplication($application)
      const secret = randomBytes(16).toString('hex');
      const insertResult = await client.query(
        `INSERT INTO bb_application (
          status, session_id, building_name, building_id,
          activity_id, contact_name, contact_email, contact_phone,
          responsible_street, responsible_zip_code, responsible_city,
          customer_identifier_type, customer_organization_number,
          created, modified, secret, owner_id, name, organizer,
          recurring_info, homepage, description, equipment
        ) VALUES (
          'NEWPARTIAL1', $1, $2, $3,
          $4, 'dummy', 'dummy@example.com', 'dummy',
          'dummy', '0000', 'dummy',
          'organization_number', '',
          NOW(), NOW(), $5, $6, $7, 'dummy',
          NULL, NULL, NULL, NULL
        ) RETURNING id`,
        [
          sessionId, buildingName, buildingId,
          resource.activity_id, secret, ownerId,
          resource.name + ' (simple booking)',
        ],
      );
      const applicationId = insertResult.rows[0].id;

      // Add resource
      // PHP: $this->applicationRepository->saveApplicationResources($id, [$resourceId])
      // PHP: First delete existing resources (ApplicationRepository.php line 663-665)
      await client.query(
        `DELETE FROM bb_application_resource WHERE application_id = $1`,
        [applicationId],
      );
      await client.query(
        `INSERT INTO bb_application_resource (application_id, resource_id)
         VALUES ($1, $2)`,
        [applicationId, resourceId],
      );

      // Add date
      // PHP: $this->applicationRepository->saveApplicationDates($id, [['from_' => $from, 'to_' => $to]])
      // PHP: First delete existing dates (ApplicationRepository.php line 687-689)
      await client.query(
        `DELETE FROM bb_application_date WHERE application_id = $1`,
        [applicationId],
      );
      // PHP: formatDateForDatabase — converts ISO (with 'T') from UTC to Europe/Oslo, pass-through otherwise
      const formattedFrom = this.formatDateForDatabase(from);
      const formattedTo = this.formatDateForDatabase(to);
      await client.query(
        `INSERT INTO bb_application_date (application_id, from_, to_)
         VALUES ($1, $2, $3)`,
        [applicationId, formattedFrom, formattedTo],
      );

      // 10. Auto-assign mandatory articles
      // PHP: $this->autoAssignMandatoryArticles($id, $resourceId, $from, $to)
      await this.autoAssignMandatoryArticles(client, applicationId, resourceId, from, to);

      // 11. Update id_string
      // PHP: $this->applicationRepository->updateIdString()
      await client.query(
        "UPDATE bb_application SET id_string = cast(id AS varchar)",
      );

      // 12. Commit
      await client.query('COMMIT');
      startedTransaction = false;

      // 13. Release lock
      // PHP: Cache::release_atomic_lock('booking', $lockKey, $sessionId)
      await this.releaseLock(lockKey, sessionId);
      lockAcquired = false;

      this.logger.log(
        `Simple booking created: app #${applicationId}, resource ${resourceId}`,
      );

      return { id: applicationId, status: 'NEWPARTIAL1' };
    } catch (err: any) {
      // PHP: rollback if we started the transaction
      if (startedTransaction && client) {
        await client.query('ROLLBACK').catch(() => {});
      }

      // PHP: release atomic lock in error cases
      if (lockAcquired) {
        await this.releaseLock(lockKey, sessionId).catch(() => {});
      }

      // PHP: clear database blocks since booking failed
      if (client) {
        await this.clearBlocks(client, sessionId, resourceId, from, to).catch(() => {});
      }

      throw err;
    } finally {
      if (client) client.release();
    }
  }

  /**
   * Publish notifications after successful booking (called async from gateway).
   */
  async publishBookingNotifications(
    sessionId: string,
    buildingId: number,
    resourceId: number,
    from: string,
    to: string,
    applicationId: number,
  ): Promise<void> {
    // Note: partial apps update is handled by the gateway before calling this method
    // to ensure it arrives before the timeslot update.

    // Don't clear the block here — the block is what shows the slot as "Reservert"
    // for ALL users (including other sessions). The NEWPARTIAL1 application is
    // session-scoped in getFreeTime, so only the booking user's session would see it.
    // The block is visible to everyone. This matches PHP behavior where
    // notifyTimeslotChanged runs without a session context.

    // PHP: notifyTimeslotChanged — expand query range by -1/+1 day
    const fromDate = new Date(from.replace(' ', 'T'));
    const toDate = new Date(to.replace(' ', 'T'));
    const queryStart = new Date(fromDate);
    queryStart.setDate(queryStart.getDate() - 1);
    const queryEnd = new Date(toDate);
    queryEnd.setDate(queryEnd.getDate() + 1);

    const queryStartStr = queryStart.toISOString().slice(0, 10);
    const queryEndStr = queryEnd.toISOString().slice(0, 10);

    // PHP: getAffectedTimeslots → FreeTimeService::getFreeTime(buildingId, resourceId, start, end, true, true)
    let affectedTimeslots: Record<string, any[]> = {};
    try {
      const timeslots = await this.freeTimeService.getFreeTime(
        buildingId, resourceId, queryStartStr, queryEndStr, null, true, true,
      );
      if (timeslots[resourceId]) {
        // Enrich block overlap_events with the application ID when the
        // block's time range matches the just-created booking. This lets
        // the client show the "Slett" button without a separate refetch.
        for (const ts of timeslots[resourceId]) {
          if (
            ts.overlap_event?.type === 'block' &&
            ts.overlap_event.from === from &&
            ts.overlap_event.to === to
          ) {
            ts.overlap_event = {
              id: applicationId,
              type: 'application',
              status: 'NEWPARTIAL1',
              from,
              to,
            };
          }
        }
        affectedTimeslots[resourceId] = timeslots[resourceId];
      }
    } catch (err: any) {
      this.logger.error(`Failed to get affected timeslots: ${err.message}`);
    }

    const eventData = {
      application_id: applicationId,
      from, to,
      change_type: 'overlap_status',
      affected_timeslots: affectedTimeslots,
    };

    await this.redisService.publish('room_messages', {
      type: 'room_message',
      roomId: `entity_building_${buildingId}`,
      entityType: 'building',
      entityId: buildingId,
      action: 'updated',
      message: 'Timeslot reservation changed',
      data: eventData,
      timestamp: new Date().toISOString(),
    });

    // PHP: resource notification uses flat array, not keyed by resource ID
    const resourceData = {
      ...eventData,
      resource_id: resourceId,
      affected_timeslots: affectedTimeslots[resourceId] ?? [],
    };

    await this.redisService.publish('room_messages', {
      type: 'room_message',
      roomId: `entity_resource_${resourceId}`,
      entityType: 'resource',
      entityId: resourceId,
      action: 'updated',
      message: 'Timeslot reservation changed',
      data: resourceData,
      timestamp: new Date().toISOString(),
    });
  }

  // --- Private helpers (1:1 ports of PHP methods) ---

  /**
   * Port of PHP checkSimpleBookingAvailability
   * (ApplicationService.php:1607)
   */
  private async checkSimpleBookingAvailability(
    client: any,
    resourceId: number,
    from: string,
    to: string,
    sessionId: string,
    resource: any,
    ssn: string | null,
  ): Promise<{
    available: boolean;
    supports_simple_booking?: boolean;
    message?: string;
    overlap_reason?: string;
    overlap_type?: string;
    limit_info?: { current_bookings: number; max_allowed: number; time_period_days: number } | null;
  }> {
    // PHP: Check if resource supports simple booking
    // PHP: $resource = $this->getSimpleBookingResource($resourceId);
    if (!resource) {
      return {
        available: false,
        supports_simple_booking: false,
        message: 'Resource does not support simple booking',
      };
    }

    // PHP: Check if there's already a block for this session
    const blockExists = await this.checkBlockExists(client, sessionId, resourceId, from, to);
    if (blockExists) {
      return { available: true, supports_simple_booking: true, message: 'Timeslot is already blocked for your session' };
    }

    // PHP: $bobooking = CreateObject('booking.bobooking');
    // PHP: $events = $this->getResourceEventsForBookingCheck($resourceId, $fromDateTime, $toDateTime);
    const events = await this.getResourceEventsForBookingCheck(client, resourceId, from, to);

    // PHP: $overlap_result = $bobooking->check_if_resurce_is_taken($resource, $fromDateTime, $toDateTime, $events);
    const overlapResult = this.checkIfResourceIsTaken(resource, from, to, events);

    // PHP: Process the overlap result
    let available = true;
    let overlapReason: string | null = null;
    let overlapType: string | null = null;

    if (overlapResult && typeof overlapResult === 'object') {
      // PHP: Detailed result with status, reason, type, and event
      available = !overlapResult.status;
      overlapReason = overlapResult.reason ?? null;
      overlapType = overlapResult.type ?? null;
    } else {
      // PHP: Simple boolean result
      available = !overlapResult;
    }

    // PHP: Check booking limits if the timeslot is available
    let limitInfo: { current_bookings: number; max_allowed: number; time_period_days: number } | null = null;
    if (available && ssn && resource.booking_limit_number > 0 && resource.booking_limit_number_horizont > 0) {
      const countResult = await client.query(
        `SELECT COUNT(*) as count FROM bb_application a
         JOIN bb_application_resource ar ON a.id = ar.application_id
         WHERE ar.resource_id = $1
         AND a.customer_ssn = $2
         AND a.created >= NOW() - (INTERVAL '1 day' * $3)
         AND a.status != 'REJECTED'
         AND a.active = 1`,
        [resourceId, ssn, resource.booking_limit_number_horizont],
      );
      const currentBookings = parseInt(countResult.rows[0].count, 10);
      limitInfo = {
        current_bookings: currentBookings,
        max_allowed: resource.booking_limit_number,
        time_period_days: resource.booking_limit_number_horizont,
      };

      // PHP: Check if user has exceeded their limit
      if (currentBookings >= resource.booking_limit_number) {
        return {
          available: false,
          supports_simple_booking: true,
          message: `You have reached the maximum allowed bookings (${resource.booking_limit_number}) for this resource within ${resource.booking_limit_number_horizont} days`,
          limit_info: limitInfo,
          overlap_reason: 'booking_limit_exceeded',
          overlap_type: 'disabled',
        };
      }
    }

    // PHP: Build the response with detailed information
    if (!available) {
      return {
        available: false,
        supports_simple_booking: true,
        message: this.getOverlapMessage(overlapReason, overlapType),
        overlap_reason: overlapReason ?? undefined,
        overlap_type: overlapType ?? undefined,
        limit_info: limitInfo,
      };
    }

    return { available: true, supports_simple_booking: true, message: 'Timeslot is available', limit_info: limitInfo };
  }

  /**
   * Port of PHP getResourceEventsForBookingCheck
   * (ApplicationService.php:1788)
   * Queries bb_application, bb_block, bb_event with BETWEEN-style overlap
   * PHP returns ['results' => $formattedEvents]
   */
  private async getResourceEventsForBookingCheck(
    client: any,
    resourceId: number,
    from: string,
    to: string,
  ): Promise<{ results: OverlapEvent[] }> {
    const formattedEvents: OverlapEvent[] = [];

    try {
      // Get applications (INCLUDING NEWPARTIAL1)
      // PHP: exact same BETWEEN overlap query
      const { rows: applications } = await client.query(
        `SELECT a.id, ad.from_, ad.to_, 'application' as type, a.status
         FROM bb_application a
         JOIN bb_application_resource ar ON a.id = ar.application_id
         JOIN bb_application_date ad ON a.id = ad.application_id
         WHERE ar.resource_id = $1
         AND a.active = 1
         AND a.status != 'REJECTED'
         AND ((ad.from_ BETWEEN $2 AND $3)
           OR (ad.to_ BETWEEN $2 AND $3)
           OR ($2 BETWEEN ad.from_ AND ad.to_)
           OR ($3 BETWEEN ad.from_ AND ad.to_))`,
        [resourceId, from, to],
      );

      // Get blocks
      const { rows: blocks } = await client.query(
        `SELECT b.id, b.from_, b.to_, 'block' as type, b.session_id as status
         FROM bb_block b
         WHERE b.resource_id = $1
         AND b.active = 1
         AND ((b.from_ BETWEEN $2 AND $3)
           OR (b.to_ BETWEEN $2 AND $3)
           OR ($2 BETWEEN b.from_ AND b.to_)
           OR ($3 BETWEEN b.from_ AND b.to_))`,
        [resourceId, from, to],
      );

      // Get events
      const { rows: events } = await client.query(
        `SELECT e.id, e.from_, e.to_, 'event' as type, 'ACCEPTED' as status
         FROM bb_event e
         JOIN bb_event_resource er ON e.id = er.event_id
         WHERE er.resource_id = $1
         AND e.active = 1
         AND ((e.from_ BETWEEN $2 AND $3)
           OR (e.to_ BETWEEN $2 AND $3)
           OR ($2 BETWEEN e.from_ AND e.to_)
           OR ($3 BETWEEN e.from_ AND e.to_))`,
        [resourceId, from, to],
      );

      // Process applications
      for (const app of applications) {
        formattedEvents.push({
          from_: app.from_,
          to_: app.to_,
          resources: [resourceId],
          type: 'application',
          id: app.id,
          status: app.status ?? null,
        });
      }

      // Process blocks
      for (const block of blocks) {
        formattedEvents.push({
          from_: block.from_,
          to_: block.to_,
          resources: [resourceId],
          type: 'block',
          id: block.id,
          status: block.status ?? null,
        });
      }

      // Process events
      for (const event of events) {
        formattedEvents.push({
          from_: event.from_,
          to_: event.to_,
          resources: [resourceId],
          type: 'event',
          id: event.id,
          status: event.status ?? 'ACCEPTED',
        });
      }
    } catch (err: any) {
      this.logger.error(`Error in getResourceEventsForBookingCheck: ${err.message}`);
      // PHP: Even with an error, we continue with whatever events we found
    }

    // PHP: return ['results' => $formattedEvents]
    return { results: formattedEvents };
  }

  /**
   * Port of PHP bobooking::check_if_resurce_is_taken
   * (class.bobooking.inc.php:1915)
   * 1:1 line-by-line translation of the PHP method.
   */
  private checkIfResourceIsTaken(
    resource: any,
    from: string,
    to: string,
    events: { results: OverlapEvent[] },
  ): OverlapResult | false {
    // PHP: $resource_id = $resource['id'];
    const resourceId = resource.id;
    // PHP: $booking_buffer_deadline = $resource['booking_buffer_deadline'];
    const bookingBufferDeadline = resource.booking_buffer_deadline;

    // PHP: $now = new DateTime("now", $DateTimeZone);
    // TZ=Europe/Oslo is set in Dockerfile.websocket, matching PHP behavior
    const now = new Date();

    // PHP: if($booking_buffer_deadline) { $now->modify($booking_buffer_deadline. ' Minute'); }
    if (bookingBufferDeadline) {
      now.setMinutes(now.getMinutes() + Number(bookingBufferDeadline));
    }

    // PHP: $StartTime, $endTime are DateTime objects
    const startTime = new Date(from);
    const endTime = new Date(to);

    // PHP: if ($StartTime <= $now) { return ['status' => 3, ...]; }
    if (startTime <= now) {
      return { status: 3, reason: 'time_in_past', type: 'disabled', event: null };
    }

    // PHP: $overlap = false; $overlap_reason = null; $overlap_event = null; $overlap_type = null;
    let overlap: number | false = false;
    let overlapReason: string | null = null;
    let overlapEvent: { id: number | null; type: string; status: string | null; from: string; to: string } | null = null;
    let overlapType: string | null = null;

    // PHP: foreach ($events['results'] as $event)
    for (const event of events.results) {
      // PHP: if (in_array($resource_id, $event['resources']))
      if (event.resources.includes(resourceId)) {
        // PHP: $event_start = new DateTime($event['from_'], $DateTimeZone);
        const eventStart = new Date(event.from_);
        // PHP: $event_end = new DateTime($event['to_'], $DateTimeZone);
        const eventEnd = new Date(event.to_);

        // PHP: format('Y-m-d H:i:s') helper for date formatting
        const fmtDate = (d: Date): string => {
          const Y = d.getFullYear();
          const M = String(d.getMonth() + 1).padStart(2, '0');
          const D = String(d.getDate()).padStart(2, '0');
          const h = String(d.getHours()).padStart(2, '0');
          const m = String(d.getMinutes()).padStart(2, '0');
          const s = String(d.getSeconds()).padStart(2, '0');
          return `${Y}-${M}-${D} ${h}:${m}:${s}`;
        };

        // PHP: Check for exact match or full coverage
        if (
          (eventStart <= startTime && eventEnd >= endTime) ||
          (fmtDate(eventStart) === fmtDate(startTime) && fmtDate(eventEnd) === fmtDate(endTime))
        ) {
          overlapReason = 'complete_overlap';
          overlapType = 'complete';
          overlapEvent = {
            id: event.id ?? null,
            type: event.type,
            status: event.status ?? null,
            from: fmtDate(eventStart),
            to: fmtDate(eventEnd),
          };
          overlap = (event.type === 'block' || event.status === 'NEWPARTIAL1') ? 2 : 1;
          break;
        }
        // PHP: Check for complete containment
        else if (eventStart > startTime && eventEnd < endTime) {
          overlapReason = 'complete_containment';
          overlapType = 'complete';
          overlapEvent = {
            id: event.id ?? null,
            type: event.type,
            status: event.status ?? null,
            from: fmtDate(eventStart),
            to: fmtDate(eventEnd),
          };
          overlap = (event.type === 'block' || event.status === 'NEWPARTIAL1') ? 2 : 1;
          break;
        }
        // PHP: Check for start overlap
        else if (eventStart <= startTime && eventEnd > startTime && eventEnd < endTime) {
          overlapReason = 'start_overlap';
          overlapType = 'partial';
          overlapEvent = {
            id: event.id ?? null,
            type: event.type,
            status: event.status ?? null,
            from: fmtDate(eventStart),
            to: fmtDate(eventEnd),
          };
          overlap = (event.type === 'block' || event.status === 'NEWPARTIAL1') ? 2 : 1;
          break;
        }
        // PHP: Check for end overlap
        else if (eventStart > startTime && eventStart < endTime && eventEnd >= endTime) {
          overlapReason = 'end_overlap';
          overlapType = 'partial';
          overlapEvent = {
            id: event.id ?? null,
            type: event.type,
            status: event.status ?? null,
            from: fmtDate(eventStart),
            to: fmtDate(eventEnd),
          };
          overlap = (event.type === 'block' || event.status === 'NEWPARTIAL1') ? 2 : 1;
          break;
        }
      }
    }

    // PHP: if ($overlap) { return ['status' => $overlap, ...]; }
    if (overlap) {
      return {
        status: overlap as number,
        reason: overlapReason,
        type: overlapType,
        event: overlapEvent,
      };
    }

    // PHP: return $overlap; (which is false)
    return false;
  }

  /**
   * Port of PHP getOverlapMessage
   * (ApplicationService.php:1723)
   */
  private getOverlapMessage(reason: string | null, type: string | null): string {
    if (!reason) return 'Timeslot is not available';

    switch (reason) {
      case 'time_in_past':
        return 'Booking time is in the past';
      case 'complete_overlap':
        return 'Timeslot is already booked';
      case 'complete_containment':
        return 'Another booking exists within this timeslot';
      case 'start_overlap':
        return 'Timeslot overlaps with the start of another booking';
      case 'end_overlap':
        return 'Timeslot overlaps with the end of another booking';
      default:
        return 'Timeslot is not available: ' + reason;
    }
  }

  /**
   * Port of PHP autoAssignMandatoryArticles
   * (ApplicationService.php:1567)
   *
   * PHP flow:
   * 1. getArticlesByResources([$resourceId]) → returns all articles for resource
   * 2. Filter by mandatory flag (resource articles with cat_id=1 are mandatory)
   * 3. saveArticlesForApplication() → delete existing PO lines, get/create PO, save each
   */
  private async autoAssignMandatoryArticles(
    client: any,
    applicationId: number,
    resourceId: number,
    from: string,
    to: string,
  ): Promise<void> {
    // PHP: $articleRepository->getArticlesByResources([$resourceId])
    // PHP: ArticleRepository.php line 221-238 — full query with bb_article_group JOIN
    const { rows: resourceArticles } = await client.query(
      `SELECT bb_article_mapping.id AS mapping_id,
        bb_article_mapping.article_cat_id || '_' || bb_article_mapping.article_id AS article_id,
        bb_resource.name as name,
        bb_article_mapping.article_id AS resource_id,
        bb_article_mapping.unit,
        fm_ecomva.percent_ AS tax_percent,
        bb_article_mapping.tax_code,
        bb_article_mapping.group_id,
        bb_article_group.name AS article_group_name,
        bb_article_group.remark AS article_group_remark
       FROM bb_article_mapping
       JOIN bb_resource ON (bb_article_mapping.article_id = bb_resource.id)
       JOIN fm_ecomva ON (bb_article_mapping.tax_code = fm_ecomva.id)
       JOIN bb_article_group ON (bb_article_mapping.group_id = bb_article_group.id)
       WHERE bb_article_mapping.article_cat_id = 1
       AND bb_resource.active = 1
       AND bb_article_mapping.article_id = $1
       ORDER BY bb_resource.name`,
      [resourceId],
    );

    if (resourceArticles.length === 0) return;

    // PHP: foreach ($articles as $article) { if (empty($article['mandatory']) || empty($article['id'])) continue; ... }
    // PHP: mandatory = isset($articleData['resource_id']) ? 1 : '' (ArticleRepository.php line 348)
    // For cat_id=1 resource articles, resource_id is always set, so mandatory = 1
    const mandatoryArticles: Array<{
      id: number;
      quantity: number;
      parent_id: number | null;
    }> = [];

    for (const article of resourceArticles) {
      // PHP: mandatory = isset($articleData['resource_id']) ? 1 : ''
      const mandatory = article.resource_id != null ? 1 : 0;
      if (!mandatory || !article.mapping_id) {
        continue;
      }

      // PHP: Calculate quantity based on unit
      let quantity = 1;
      if (article.unit === 'hour') {
        const fromTime = new Date(from);
        const toTime = new Date(to);
        // PHP: ($toTime->getTimestamp() - $fromTime->getTimestamp()) / 3600
        // getTimestamp() returns seconds, getTime() returns milliseconds
        const diffHours = (toTime.getTime() - fromTime.getTime()) / 3600000;
        quantity = Math.max(1, Math.ceil(diffHours));
      }

      mandatoryArticles.push({
        id: article.mapping_id,
        quantity,
        parent_id: null,
      });
    }

    if (mandatoryArticles.length === 0) return;

    // PHP: $articleRepository->saveArticlesForApplication($applicationId, $mandatoryArticles)
    // PHP: ArticleRepository.php line 85-117

    // PHP: $this->deleteExistingPurchaseOrderLines($applicationId) — line 124-140
    const { rows: existingPo } = await client.query(
      `SELECT id FROM bb_purchase_order WHERE application_id = $1`,
      [applicationId],
    );
    if (existingPo.length > 0) {
      await client.query(
        `DELETE FROM bb_purchase_order_line WHERE order_id = $1`,
        [existingPo[0].id],
      );
    }

    // PHP: $purchase_order_id = $this->getOrCreatePurchaseOrder($applicationId) — line 148-166
    let orderId: number;
    const { rows: poRows } = await client.query(
      `SELECT id FROM bb_purchase_order WHERE application_id = $1`,
      [applicationId],
    );
    if (poRows.length > 0) {
      orderId = poRows[0].id;
    } else {
      const poResult = await client.query(
        `INSERT INTO bb_purchase_order (application_id) VALUES ($1) RETURNING id`,
        [applicationId],
      );
      orderId = poResult.rows[0].id;
    }

    // PHP: foreach ($articles as $article) { ... } — line 95-113
    for (const article of mandatoryArticles) {
      // PHP: $mapping = $this->getArticleMappingById($article['id']) — line 24-39
      const { rows: mappingRows } = await client.query(
        `SELECT am.*, p.price, am.tax_code, e.percent_ AS tax_percent
         FROM bb_article_mapping am
         LEFT JOIN bb_article_price p ON p.article_mapping_id = am.id AND p.active = 1 AND p.from_ <= CURRENT_DATE
         LEFT JOIN fm_ecomva e ON am.tax_code = e.id
         WHERE am.id = $1
         ORDER BY p.default_ ASC, p.from_ DESC
         LIMIT 1`,
        [article.id],
      );

      if (mappingRows.length === 0) {
        continue; // PHP: if (!$mapping) continue;
      }

      const mapping = mappingRows[0];

      // PHP: $line = [...] — line 103-110
      const unitPrice = parseFloat(mapping.price || '0'); // ex_tax_price
      const quantity = article.quantity;
      const taxPercent = parseFloat(mapping.tax_percent || '0');

      // PHP: savePurchaseOrderLine($purchase_order_id, $line) — line 171-202
      const amount = unitPrice * quantity;
      const tax = amount * (taxPercent / 100);

      await client.query(
        `INSERT INTO bb_purchase_order_line (
          order_id, article_mapping_id, quantity,
          tax_code, unit_price, parent_mapping_id, amount, tax, currency
        ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'NOK')`,
        [orderId, article.id, quantity, mapping.tax_code, unitPrice, article.parent_id, amount, tax],
      );
    }
  }

  /**
   * Port of PHP ApplicationRepository::createBlock
   * (ApplicationRepository.php:1136)
   */
  private async createBlock(
    client: any,
    sessionId: string,
    resourceId: number,
    from: string,
    to: string,
  ): Promise<boolean> {
    try {
      // PHP: Check if block already exists
      const exists = await this.checkBlockExists(client, sessionId, resourceId, from, to);
      if (exists) return true;

      // PHP: Create new block
      await client.query(
        `INSERT INTO bb_block (session_id, resource_id, from_, to_, active)
         VALUES ($1, $2, $3, $4, 1)`,
        [sessionId, resourceId, from, to],
      );

      return true;
    } catch (err: any) {
      this.logger.error(`Error creating block: ${err.message}`);
      return false;
    }
  }

  /**
   * Port of PHP ApplicationRepository::checkBlockExists
   * (ApplicationRepository.php:1106)
   */
  private async checkBlockExists(
    client: any,
    sessionId: string,
    resourceId: number,
    from: string,
    to: string,
  ): Promise<boolean> {
    const { rows } = await client.query(
      `SELECT 1 FROM bb_block
       WHERE active = 1 AND session_id = $1
       AND resource_id = $2 AND from_ = $3 AND to_ = $4
       LIMIT 1`,
      [sessionId, resourceId, from, to],
    );
    return rows.length > 0;
  }

  /**
   * Clear blocks on failure (matches PHP error handling pattern)
   */
  private async clearBlocks(
    client: any,
    sessionId: string,
    resourceId: number,
    from: string,
    to: string,
  ): Promise<void> {
    try {
      await client.query(
        `UPDATE bb_block SET active = 0
         WHERE session_id = $1 AND resource_id = $2
         AND from_ = $3 AND to_ = $4`,
        [sessionId, resourceId, from, to],
      );
    } catch {
      // PHP: Just log this error but don't interrupt the flow
    }
  }

  /**
   * Port of PHP ApplicationRepository::formatDateForDatabase
   * (ApplicationRepository.php)
   * If the string contains 'T' (ISO format), parse as UTC, convert to Europe/Oslo, format as Y-m-d H:i:s.
   * Otherwise pass through as-is.
   */
  private formatDateForDatabase(dateString: string): string {
    if (dateString.includes('T')) {
      const utcDate = new Date(dateString);
      // Convert to Europe/Oslo using Intl
      const parts = new Intl.DateTimeFormat('sv-SE', {
        timeZone: 'Europe/Oslo',
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false,
      }).formatToParts(utcDate);

      const get = (type: string) => parts.find(p => p.type === type)?.value ?? '00';
      return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')}:${get('second')}`;
    }
    return dateString;
  }

  // --- Redis lock helpers (matching PHP Cache::_gen_key + RedisCache) ---

  private genRedisKey(module: string, id: string): string {
    const raw = `${this.installId || ''}::${module}::${id}`;
    return createHash('sha1').update(raw).digest('hex');
  }

  private async acquireLock(lockId: string, value: string, ttl: number): Promise<boolean> {
    const key = this.genRedisKey('booking', lockId);
    return this.redisService.setnx(key, value, ttl);
  }

  private async releaseLock(lockId: string, value: string): Promise<boolean> {
    const key = this.genRedisKey('booking', lockId);
    return this.redisService.releaseLock(key, value);
  }
}
