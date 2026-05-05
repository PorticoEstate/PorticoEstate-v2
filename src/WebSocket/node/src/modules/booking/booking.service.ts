import { Injectable, Logger, OnModuleInit } from '@nestjs/common';
import { createHash, randomBytes } from 'crypto';
import * as fs from 'fs';
import { DatabaseService } from '../../database/database.service';
import { RedisService } from '../notification/redis.service';

/**
 * Source file verification.
 * This Node booking service was ported from these PHP files at the commit/MD5s below.
 * If any source file has changed, the WS booking endpoint is DISABLED and clients
 * must fall back to the PHP REST endpoint.
 *
 * Ported from commit: 46900f8e (Cross approval better for vipps)
 */
const PHP_SOURCE_CHECKSUMS: Record<string, string> = {
  '/var/www/html/src/modules/bookingfrontend/services/applications/ApplicationService.php':
    'f299fe80568ba28e13ea3171432fa2a1',
  '/var/www/html/src/modules/bookingfrontend/repositories/ApplicationRepository.php':
    '0316a204e511eee6bb24fde3a4e9a64f',
  '/var/www/html/src/modules/bookingfrontend/repositories/ArticleRepository.php':
    'f865e0ea6667e68224b4be81efff56dd',
};

interface BookingResult {
  id: number;
  status: string;
}

/**
 * Port of PHP ApplicationService::createSimpleBooking.
 * Matches the PHP flow exactly:
 *   1. Redis atomic lock
 *   2. BEGIN transaction
 *   3. SELECT FOR UPDATE overlap check
 *   4. Verify resource supports simple booking
 *   5. Resolve owner_id (from param or session history, fallback 0)
 *   6. Check booking limits if SSN available
 *   7. Create block
 *   8. Insert application + resources + dates
 *   9. Auto-assign mandatory articles
 *  10. Update id_string
 *  11. COMMIT
 *  12. Release lock
 */
@Injectable()
export class BookingService implements OnModuleInit {
  private readonly logger = new Logger(BookingService.name);
  private installId: string | null = null;
  private sourceVerified = false;

  constructor(
    private readonly db: DatabaseService,
    private readonly redisService: RedisService,
  ) {}

  async onModuleInit() {
    // Verify PHP source files haven't changed since this port was made
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

  /**
   * Check if the WS booking endpoint is safe to use.
   * Returns false if PHP source files have changed — client must use REST fallback.
   */
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

  async createSimpleBooking(
    resourceId: number,
    buildingId: number,
    from: string,
    to: string,
    sessionId: string,
    ownerId?: number,
  ): Promise<BookingResult> {
    const lockKey = `booking_lock_${resourceId}_${from}_${to}`;
    const lockTtl = 30;
    let lockAcquired = false;
    let client: any = null;

    try {
      // 1. Acquire Redis atomic lock (same key as PHP)
      lockAcquired = await this.acquireLock(lockKey, sessionId, lockTtl);
      if (!lockAcquired) {
        throw new Error('Resource is already being booked by another user');
      }

      // 2. Get dedicated DB client and begin transaction
      client = await this.db.getClient();
      await client.query('BEGIN');

      try {
        // 3. Atomic overlap check with row locking
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
          // Clear blocks on conflict (same as PHP)
          await this.clearBlocks(client, sessionId, resourceId, from, to);
          throw new Error('Resource is already booked for this time slot');
        }

        // 3b. Check for conflicts with events, allocations, bookings, and blocks
        //     (matches PHP checkSimpleBookingAvailability's event/allocation/booking checks)
        const conflictResult = await client.query(
          `SELECT 'event' as type, e.id FROM bb_event e
           JOIN bb_event_resource er ON er.event_id = e.id
           WHERE er.resource_id = $1 AND e.active = 1
           AND ((e.from_ < $2 AND e.to_ > $3) AND NOT (e.from_ = $2 OR e.to_ = $3))
           UNION ALL
           SELECT 'allocation' as type, a.id FROM bb_allocation a
           JOIN bb_allocation_resource ar ON ar.allocation_id = a.id
           JOIN bb_season s ON a.season_id = s.id
           WHERE ar.resource_id = $1 AND a.active = 1 AND s.active = 1 AND s.status = 'PUBLISHED'
           AND ((a.from_ < $2 AND a.to_ > $3) AND NOT (a.from_ = $2 OR a.to_ = $3))
           UNION ALL
           SELECT 'booking' as type, b.id FROM bb_booking b
           JOIN bb_booking_resource br ON br.booking_id = b.id
           JOIN bb_season s ON b.season_id = s.id
           WHERE br.resource_id = $1 AND b.active = 1 AND s.active = 1 AND s.status = 'PUBLISHED'
           AND ((b.from_ < $2 AND b.to_ > $3) AND NOT (b.from_ = $2 OR b.to_ = $3))
           UNION ALL
           SELECT 'block' as type, bl.id FROM bb_block bl
           WHERE bl.resource_id = $1 AND bl.active = 1 AND bl.session_id != $4
           AND ((bl.from_ < $2 AND bl.to_ > $3) AND NOT (bl.from_ = $2 OR bl.to_ = $3))
           LIMIT 1`,
          [resourceId, to, from, sessionId],
        );

        if (conflictResult.rows.length > 0) {
          const conflict = conflictResult.rows[0];
          await this.clearBlocks(client, sessionId, resourceId, from, to);
          throw new Error(`Timeslot is not available (conflict with ${conflict.type} #${conflict.id})`);
        }

        // 4. Verify resource supports simple booking
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

        // 5. Resolve owner_id — same as PHP: $this->userSettings['account_id'] ?? 0
        //    PHP gets this from the session system (phpgw_access_log maps session → account_id)
        let resolvedOwnerId = ownerId || 0;
        let ssn: string | null = null;

        if (!resolvedOwnerId) {
          // Look up account_id from phpgw_access_log (same source as PHP session system)
          const sessionAccount = await client.query(
            `SELECT account_id FROM phpgw_access_log
             WHERE sessionid = $1 AND account_id > 0
             LIMIT 1`,
            [sessionId],
          );
          if (sessionAccount.rows[0]) {
            resolvedOwnerId = sessionAccount.rows[0].account_id;
          }
        }

        // Resolve SSN from user's previous applications (for booking limit check)
        if (resolvedOwnerId > 0) {
          const ssnRow = await client.query(
            `SELECT customer_ssn FROM bb_application
             WHERE owner_id = $1 AND customer_ssn IS NOT NULL AND customer_ssn != ''
             LIMIT 1`,
            [resolvedOwnerId],
          );
          ssn = ssnRow.rows[0]?.customer_ssn || null;
        }

        // 6. Check booking limits — only if SSN available AND resource has limits
        //    Matches PHP: if ($ssn && $resource['booking_limit_number'] > 0 && ...)
        if (
          ssn &&
          resource.booking_limit_number > 0 &&
          resource.booking_limit_number_horizont > 0
        ) {
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
              `Booking limit exceeded for ${resource.name}: ` +
              `maximum ${resource.booking_limit_number} within ` +
              `${resource.booking_limit_number_horizont} days`,
            );
          }
        }

        // 7. Create block (same as PHP createBlock)
        const blockExists = await this.checkBlockExists(
          client, sessionId, resourceId, from, to,
        );
        if (!blockExists) {
          await client.query(
            `INSERT INTO bb_block (session_id, resource_id, from_, to_, active)
             VALUES ($1, $2, $3, $4, 1)`,
            [sessionId, resourceId, from, to],
          );
        }

        // 8. Get building name
        const buildingResult = await client.query(
          'SELECT name FROM bb_building WHERE id = $1',
          [buildingId],
        );
        if (buildingResult.rows.length === 0) {
          throw new Error('Building not found');
        }
        const buildingName = buildingResult.rows[0].name;

        // 9. Insert application (matches PHP insertApplication fields exactly)
        const secret = randomBytes(16).toString('hex');
        const insertResult = await client.query(
          `INSERT INTO bb_application (
            status, session_id, building_name, building_id,
            activity_id, contact_name, contact_email, contact_phone,
            responsible_street, responsible_zip_code, responsible_city,
            customer_identifier_type, customer_organization_number,
            created, modified, secret, owner_id, name, organizer,
            recurring_info, homepage, description, equipment, active
          ) VALUES (
            'NEWPARTIAL1', $1, $2, $3,
            $4, 'dummy', 'dummy@example.com', 'dummy',
            'dummy', '0000', 'dummy',
            'organization_number', '',
            NOW(), NOW(), $5, $6, $7, 'dummy',
            NULL, NULL, NULL, NULL, 1
          ) RETURNING id`,
          [
            sessionId, buildingName, buildingId,
            resource.activity_id, secret, resolvedOwnerId,
            resource.name + ' (simple booking)',
          ],
        );
        const applicationId = insertResult.rows[0].id;

        // Add resource
        await client.query(
          `INSERT INTO bb_application_resource (application_id, resource_id)
           VALUES ($1, $2)`,
          [applicationId, resourceId],
        );

        // Add date
        await client.query(
          `INSERT INTO bb_application_date (application_id, from_, to_)
           VALUES ($1, $2, $3)`,
          [applicationId, from, to],
        );

        // 10. Auto-assign mandatory articles (same as PHP autoAssignMandatoryArticles)
        await this.autoAssignMandatoryArticles(client, applicationId, resourceId, from, to);

        // 11. Update id_string (same as PHP)
        await client.query(
          "UPDATE bb_application SET id_string = cast(id AS varchar)",
        );

        // 12. Commit
        await client.query('COMMIT');

        // Release lock
        await this.releaseLock(lockKey, sessionId);
        lockAcquired = false;

        this.logger.log(
          `Simple booking created: app #${applicationId}, resource ${resourceId}`,
        );

        return { id: applicationId, status: 'NEWPARTIAL1' };
      } catch (err) {
        await client.query('ROLLBACK').catch(() => {});
        throw err;
      }
    } catch (err: any) {
      // Release lock if still held
      if (lockAcquired) {
        await this.releaseLock(lockKey, sessionId).catch(() => {});
      }
      // Clear blocks on failure (same as PHP catch block)
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
  ): Promise<void> {
    await this.redisService.publish('session_messages', {
      type: 'update_partial_applications',
      sessionId,
      timestamp: new Date().toISOString(),
    });

    const eventData = {
      from, to,
      change_type: 'overlap_status',
      affected_timeslots: {},
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

    await this.redisService.publish('room_messages', {
      type: 'room_message',
      roomId: `entity_resource_${resourceId}`,
      entityType: 'resource',
      entityId: resourceId,
      action: 'updated',
      message: 'Timeslot reservation changed',
      data: { ...eventData, resource_id: resourceId },
      timestamp: new Date().toISOString(),
    });
  }

  // --- Private helpers ---

  private async autoAssignMandatoryArticles(
    client: any,
    applicationId: number,
    resourceId: number,
    from: string,
    to: string,
  ): Promise<void> {
    // Get resource article mapping (article_cat_id=1 = resource article)
    // Same query logic as PHP ArticleRepository::getArticlesByResources for mandatory items
    const { rows: mappings } = await client.query(
      `SELECT am.id, am.unit, am.tax_code, p.price, e.percent_ AS tax_percent
       FROM bb_article_mapping am
       JOIN bb_resource ON (am.article_id = bb_resource.id)
       LEFT JOIN bb_article_price p ON p.article_mapping_id = am.id
         AND p.active = 1 AND p.from_ <= CURRENT_DATE
       LEFT JOIN fm_ecomva e ON am.tax_code = e.id
       WHERE am.article_cat_id = 1
         AND am.article_id = $1
         AND bb_resource.active = 1
       ORDER BY p.default_ ASC, p.from_ DESC`,
      [resourceId],
    );

    if (mappings.length === 0) return;

    // Use first (highest priority price) — resource articles are mandatory
    const mapping = mappings[0];

    // Calculate quantity (same as PHP: if unit='hour', use duration)
    let quantity = 1;
    if (mapping.unit === 'hour') {
      const fromTime = new Date(from);
      const toTime = new Date(to);
      const diffHours = (toTime.getTime() - fromTime.getTime()) / 3600000;
      quantity = Math.max(1, Math.ceil(diffHours));
    }

    // Create purchase order (same as PHP getOrCreatePurchaseOrder)
    const poResult = await client.query(
      `INSERT INTO bb_purchase_order (application_id) VALUES ($1) RETURNING id`,
      [applicationId],
    );
    const orderId = poResult.rows[0].id;

    // Create purchase order line (same as PHP savePurchaseOrderLine)
    const unitPrice = parseFloat(mapping.price || '0');
    const taxPercent = parseFloat(mapping.tax_percent || '0');
    const amount = unitPrice * quantity;
    const tax = amount * (taxPercent / 100);

    await client.query(
      `INSERT INTO bb_purchase_order_line (
        order_id, article_mapping_id, quantity,
        tax_code, unit_price, parent_mapping_id, amount, tax, currency
      ) VALUES ($1, $2, $3, $4, $5, NULL, $6, $7, 'NOK')`,
      [orderId, mapping.id, quantity, mapping.tax_code, unitPrice, amount, tax],
    );
  }

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
      // Don't interrupt flow
    }
  }

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
