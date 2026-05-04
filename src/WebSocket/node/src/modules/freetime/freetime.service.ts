import { Injectable, Logger } from '@nestjs/common';
import { DatabaseService } from '../../database/database.service';

/**
 * Port of PHP FreeTimeService.
 * Replicates exact behavior including all quirks and preserved bugs
 * to ensure backward compatibility with the PHP endpoint.
 */

const TIMEZONE = 'Europe/Oslo';
const DATEFORMAT = 'd/m-Y';

interface Resource {
  id: number;
  simple_booking: number;
  simple_booking_start_date: number | null;
  simple_booking_end_date: number | null;
  booking_month_horizon: number | null;
  booking_day_horizon: number | null;
  booking_dow_default_start: number;
  booking_day_default_lenght: number; // preserves original typo
  booking_time_default_start: number;
  booking_time_default_end: number;
  booking_time_minutes: number;
  booking_buffer_deadline: number | null;
  skip_timeslot?: boolean;
  from?: Date;
  to?: Date;
  [key: string]: any;
}

interface EntityResult {
  id: number;
  from_: string;
  to_: string;
  type: string;
  status?: string;
  resources: number[];
}

interface Events {
  results: EntityResult[];
}

interface OverlapResult {
  status: number;
  reason: string;
  type: string;
  event?: {
    id: number | null;
    type: string;
    status: string | null;
    from: string;
    to: string;
  };
}

interface TimeSlot {
  when: string;
  start: string;
  end: string;
  overlap: number | false;
  start_iso: string;
  end_iso: string;
  resource_id?: number;
  overlap_reason?: string;
  overlap_type?: string;
  overlap_event?: any;
  applicationLink?: any;
}

@Injectable()
export class FreeTimeService {
  private readonly logger = new Logger(FreeTimeService.name);

  // Caches (per-request, reset on each call)
  private seasonCache = new Map<string, number[]>();
  private seasonDataCache = new Map<number, any>();
  private seasonBoundaryCache = new Map<number, any[]>();

  constructor(private readonly db: DatabaseService) {}

  async getFreeTime(
    buildingId: number,
    resourceId: number | null,
    startDateStr: string,
    endDateStr: string,
    sessionId: string | null,
    detailedOverlap = false,
    stopOnEndDate = false,
  ): Promise<Record<string, TimeSlot[]>> {
    // Reset caches per request
    this.seasonCache.clear();
    this.seasonDataCache.clear();
    this.seasonBoundaryCache.clear();

    const startTimestamp = new Date(startDateStr).getTime();
    const endTimestamp = new Date(endDateStr).getTime();
    if (isNaN(startTimestamp) || isNaN(endTimestamp)) return {};

    // Booking horizon
    const maxEndDate = await this.calculateBookingHorizonEndDate(
      buildingId,
      resourceId,
    );
    if (startTimestamp > maxEndDate) return {};
    const effectiveEnd = Math.min(endTimestamp, maxEndDate);

    const startDate = toOsloDate(startDateStr);
    const endDate = toOsloDate(
      new Date(effectiveEnd).toISOString().slice(0, 10),
    );

    return this.getFreeEvents(
      buildingId,
      resourceId,
      startDate,
      endDate,
      stopOnEndDate,
      detailedOverlap,
      sessionId,
    );
  }

  private async calculateBookingHorizonEndDate(
    buildingId: number,
    resourceId: number | null,
  ): Promise<number> {
    let bookingMonthHorizon = 2;

    if (resourceId) {
      const { rows } = await this.db.query(
        'SELECT booking_month_horizon FROM bb_resource WHERE id = $1 AND active = 1',
        [resourceId],
      );
      if (rows[0]?.booking_month_horizon) {
        const h = Number(rows[0].booking_month_horizon);
        if (h > bookingMonthHorizon + 1) bookingMonthHorizon = h + 1;
      }
    } else {
      const { rows } = await this.db.query(
        `SELECT r.booking_month_horizon FROM bb_resource r
         JOIN bb_building_resource br ON br.resource_id = r.id
         WHERE br.building_id = $1 AND r.active = 1`,
        [buildingId],
      );
      for (const row of rows) {
        if (row.booking_month_horizon) {
          const h = Number(row.booking_month_horizon);
          if (h > bookingMonthHorizon + 1) bookingMonthHorizon = h + 1;
        }
      }
    }

    const d = new Date();
    d.setMonth(d.getMonth() + bookingMonthHorizon);
    // last day of that month
    d.setMonth(d.getMonth() + 1, 0);
    d.setHours(23, 59, 59, 0);
    return d.getTime();
  }

  private async getFreeEvents(
    buildingId: number,
    resourceId: number | null,
    startDate: Date,
    endDate: Date,
    stopOnEndDate: boolean,
    detailedOverlap: boolean,
    sessionId: string | null,
  ): Promise<Record<string, TimeSlot[]>> {
    const _from = new Date(startDate);
    _from.setHours(0, 0, 0, 0);
    const _to = new Date(endDate);
    _to.setHours(23, 59, 59, 0);

    // Fetch resources
    const resources = await this.fetchResources(buildingId, resourceId);
    const resourceIds: number[] = [];
    let eventIds: number[] = [];
    let allocationIds: number[] = [];
    let bookingIds: number[] = [];

    const now = osloNow();

    for (const resource of resources) {
      resourceIds.push(resource.id);
      let from = new Date(_from);

      // simple_booking_start_date handling
      if (resource.simple_booking_start_date) {
        const sbsd = new Date(resource.simple_booking_start_date * 1000);
        if (sbsd > now) {
          resource.skip_timeslot = true;
        }
        if (sbsd > _from) {
          from = new Date(sbsd);
        } else {
          from.setHours(sbsd.getHours(), sbsd.getMinutes(), 0, 0);
        }
      }

      let to = new Date(_to);

      // booking_day_horizon
      if (resource.booking_day_horizon) {
        const base = !resource.booking_month_horizon
          ? new Date(from)
          : new Date(to);
        base.setDate(base.getDate() + Number(resource.booking_day_horizon));
        to = base;
      }

      // booking_month_horizon
      if (resource.booking_month_horizon) {
        to = this.monthShifter(
          new Date(from),
          Number(resource.booking_month_horizon),
        );
        to.setHours(23, 59, 59, 0);
      }

      // simple_booking_end_date
      if (resource.simple_booking_end_date) {
        const sbed = new Date(resource.simple_booking_end_date * 1000);
        if (sbed < to) to = new Date(sbed);
        to.setHours(23, 59, 59, 0);
      }

      // Fetch entity IDs
      if (resource.simple_booking && !resource.skip_timeslot) {
        const [eIds, aIds, bIds] = await Promise.all([
          this.entityIdsForResource('event', resource.id, _from, to),
          this.entityIdsForResource('allocation', resource.id, from, to),
          this.entityIdsForResource('booking', resource.id, from, to),
        ]);
        eventIds = eventIds.concat(eIds);
        allocationIds = allocationIds.concat(aIds);
        bookingIds = bookingIds.concat(bIds);
      }

      resource.from = from;
      if (resource.booking_time_default_end > -1) {
        to.setHours(Number(resource.booking_time_default_end), 0, 0, 0);
      }
      resource.to = to;
    }

    // Fetch full entities
    const [events, allocations, bookings] = await Promise.all([
      this.fetchEntities('event', eventIds),
      this.fetchEntities('allocation', allocationIds),
      this.fetchEntities('booking', bookingIds),
    ]);

    const allEvents: Events = {
      results: [
        ...events.results,
        ...allocations.results,
        ...bookings.results,
      ],
    };

    // Get partials and blocks
    await this.getPartials(allEvents, resourceIds, _from, _to, sessionId);

    // Prefetch all season data for sync access in the hot loop
    await this.prefetchSeasonData(resources, _from, _to);

    // Generate timeslots
    return this.generateTimeSlots(
      resources,
      allEvents,
      buildingId,
      _to,
      stopOnEndDate,
      detailedOverlap,
    );
  }

  private async fetchResources(
    buildingId: number,
    resourceId: number | null,
  ): Promise<Resource[]> {
    if (resourceId) {
      const { rows } = await this.db.query(
        `SELECT r.* FROM bb_resource r
         JOIN bb_rescategory rc ON rc.id = r.rescategory_id
         WHERE r.id = $1 AND r.active = 1 AND rc.active = 1`,
        [resourceId],
      );
      return rows;
    }
    const { rows } = await this.db.query(
      `SELECT r.* FROM bb_resource r
       JOIN bb_building_resource br ON br.resource_id = r.id
       JOIN bb_rescategory rc ON rc.id = r.rescategory_id
       WHERE br.building_id = $1 AND r.active = 1 AND rc.active = 1
       ORDER BY r.sort`,
      [buildingId],
    );
    return rows;
  }

  private async entityIdsForResource(
    type: 'event' | 'allocation' | 'booking',
    resourceId: number,
    start: Date,
    end: Date,
  ): Promise<number[]> {
    const startStr = fmtDateTime(start);
    const endStr = fmtDateTime(end);

    let sql: string;
    switch (type) {
      case 'event':
        sql = `SELECT id FROM bb_event
               JOIN bb_event_resource ON (event_id = id AND resource_id = $1)
               WHERE active = 1
               AND ((from_ >= $2 AND from_ < $3) OR (to_ > $2 AND to_ <= $3) OR (from_ < $2 AND to_ > $3))`;
        break;
      case 'allocation':
        sql = `SELECT DISTINCT bb_allocation.id AS id
               FROM bb_allocation
               JOIN bb_allocation_resource ON (allocation_id = bb_allocation.id AND resource_id = $1)
               JOIN bb_season ON (bb_allocation.season_id = bb_season.id)
               WHERE bb_allocation.active = 1 AND bb_season.active = 1 AND bb_season.status = 'PUBLISHED'
               AND ((bb_allocation.from_ >= $2 AND bb_allocation.from_ < $3)
                 OR (bb_allocation.to_ > $2 AND bb_allocation.to_ <= $3)
                 OR (bb_allocation.from_ < $2 AND bb_allocation.to_ > $3))`;
        break;
      case 'booking':
        sql = `SELECT bb_booking.id AS id
               FROM bb_booking
               JOIN bb_booking_resource ON (booking_id = bb_booking.id AND resource_id = $1)
               JOIN bb_season ON (bb_booking.season_id = bb_season.id)
               WHERE bb_booking.active = 1 AND bb_season.active = 1 AND bb_season.status = 'PUBLISHED'
               AND ((bb_booking.from_ >= $2 AND bb_booking.from_ < $3)
                 OR (bb_booking.to_ > $2 AND bb_booking.to_ <= $3)
                 OR (bb_booking.from_ < $2 AND bb_booking.to_ > $3))`;
        break;
    }

    const { rows } = await this.db.query(sql, [resourceId, startStr, endStr]);
    return rows.map((r) => r.id);
  }

  private async fetchEntities(
    type: string,
    ids: number[],
  ): Promise<Events> {
    if (!ids.length) return { results: [] };
    const unique = [...new Set(ids)];
    const placeholders = unique.map((_, i) => `$${i + 1}`).join(',');

    let sql: string;
    switch (type) {
      case 'event':
        sql = `SELECT e.id, e.from_, e.to_, e.active, e.name, er.resource_id
               FROM bb_event e JOIN bb_event_resource er ON er.event_id = e.id
               WHERE e.id IN (${placeholders})`;
        break;
      case 'allocation':
        sql = `SELECT a.id, a.from_, a.to_, a.active, ar.resource_id
               FROM bb_allocation a JOIN bb_allocation_resource ar ON ar.allocation_id = a.id
               WHERE a.id IN (${placeholders})`;
        break;
      case 'booking':
        sql = `SELECT b.id, b.from_, b.to_, b.active, br.resource_id
               FROM bb_booking b JOIN bb_booking_resource br ON br.booking_id = b.id
               WHERE b.id IN (${placeholders})`;
        break;
      default:
        return { results: [] };
    }

    const { rows } = await this.db.query(sql, unique);

    const grouped = new Map<number, EntityResult>();
    for (const row of rows) {
      const id = Number(row.id);
      if (!grouped.has(id)) {
        grouped.set(id, {
          id,
          from_: row.from_,
          to_: row.to_,
          type,
          resources: [],
        });
      }
      grouped.get(id)!.resources.push(Number(row.resource_id));
    }
    return { results: Array.from(grouped.values()) };
  }

  private async getPartials(
    events: Events,
    resourceIds: number[],
    from: Date,
    to: Date,
    sessionId: string | null,
  ): Promise<void> {
    const fromStr = fmtDateTime(from);
    const toStr = fmtDateTime(to);

    // Partial applications for current session
    if (sessionId) {
      const { rows } = await this.db.query(
        `SELECT a.id, a.status, ad.from_, ad.to_, ar.resource_id
         FROM bb_application a
         JOIN bb_application_date ad ON ad.application_id = a.id
         JOIN bb_application_resource ar ON ar.application_id = a.id
         WHERE a.status = 'NEWPARTIAL1' AND a.session_id = $1
         AND ((ad.from_ >= $2 AND ad.from_ < $3)
           OR (ad.to_ > $2 AND ad.to_ <= $3)
           OR (ad.from_ < $2 AND ad.to_ > $3))`,
        [sessionId, fromStr, toStr],
      );

      const grouped = new Map<number, EntityResult>();
      for (const row of rows) {
        const id = Number(row.id);
        if (!grouped.has(id)) {
          grouped.set(id, {
            id,
            from_: row.from_,
            to_: row.to_,
            type: 'application',
            status: row.status,
            resources: [],
          });
        }
        grouped.get(id)!.resources.push(Number(row.resource_id));
      }
      for (const app of grouped.values()) {
        events.results.push(app);
      }
    }

    // Active blocks
    if (resourceIds.length) {
      const placeholders = resourceIds.map((_, i) => `$${i + 1}`).join(',');
      const params: any[] = [...resourceIds, fromStr, toStr, fromStr, toStr, fromStr, toStr];
      const n = resourceIds.length;
      const { rows } = await this.db.query(
        `SELECT id, from_, to_, resource_id, session_id FROM bb_block
         WHERE active = 1 AND resource_id IN (${placeholders})
         AND ((from_ >= $${n + 1} AND from_ < $${n + 2})
           OR (to_ > $${n + 3} AND to_ <= $${n + 4})
           OR (from_ < $${n + 5} AND to_ > $${n + 6}))`,
        params,
      );

      for (const block of rows) {
        if (block.session_id === sessionId) continue; // skip own session blocks
        events.results.push({
          id: Number(block.id),
          from_: block.from_,
          to_: block.to_,
          type: 'block',
          resources: [Number(block.resource_id)],
        });
      }
    }
  }

  private async getResourceSeasons(
    resourceId: number,
    from: string,
    to: string,
  ): Promise<number[]> {
    const key = `${resourceId}_${from}_${to}`;
    if (this.seasonCache.has(key)) return this.seasonCache.get(key)!;

    const { rows } = await this.db.query(
      `SELECT season_id FROM bb_season_resource
       JOIN bb_season ON bb_season.id = bb_season_resource.season_id
       WHERE status = 'PUBLISHED'
       AND ((from_ >= $1 AND from_ < $2) OR (to_ > $1 AND to_ <= $2) OR (from_ < $1 AND to_ > $2))
       AND resource_id = $3`,
      [from, to, resourceId],
    );

    const ids = rows.map((r) => Number(r.season_id));
    this.seasonCache.set(key, ids);
    return ids;
  }

  private async timespanWithinSeason(
    seasonId: number,
    from: Date,
    to: Date,
  ): Promise<boolean> {
    // Load season data (cached)
    if (!this.seasonDataCache.has(seasonId)) {
      const { rows } = await this.db.query(
        'SELECT id, from_, to_ FROM bb_season WHERE id = $1',
        [seasonId],
      );
      this.seasonDataCache.set(seasonId, rows[0] || null);
    }
    const season = this.seasonDataCache.get(seasonId);
    if (!season) return false;

    const seasonFrom = new Date(season.from_).getTime();
    const seasonTo = new Date(season.to_).getTime();
    const checkFrom = new Date(fmtDate(from)).getTime();
    const checkTo = new Date(fmtDate(to)).getTime();

    if (seasonFrom > checkFrom || seasonTo < checkTo) return false;

    const SEC_DAY = 86400;
    const daysInPeriod = Math.abs(
      (new Date(fmtDate(to)).getTime() / 1000 + SEC_DAY - new Date(fmtDate(from)).getTime() / 1000) / SEC_DAY,
    );

    let fromWeekDay: number, toWeekDay: number, fromTime: string, toTime: string;
    if (daysInPeriod <= 7) {
      fromWeekDay = isoWeekday(from);
      toWeekDay = isoWeekday(to);
      fromTime = fmtTime(from);
      toTime = fmtTime(to);
    } else {
      fromWeekDay = 1;
      toWeekDay = 7;
      fromTime = '00:00:00';
      toTime = '23:59:00';
    }

    if (fromWeekDay > toWeekDay) {
      // Wraps around week boundary
      const endOfWeek = new Date(from);
      endOfWeek.setDate(endOfWeek.getDate() + (7 - fromWeekDay));
      endOfWeek.setHours(23, 59, 0, 0);
      const startOfWeek = new Date(to);
      startOfWeek.setDate(startOfWeek.getDate() - (toWeekDay - 1));
      startOfWeek.setHours(0, 0, 0, 0);
      return (
        (await this.timespanWithinSeason(seasonId, from, endOfWeek)) &&
        (await this.timespanWithinSeason(seasonId, startOfWeek, to))
      );
    }

    // Convert to epoch-based weekday timestamps (matching PHP logic)
    const fromWdayTs = dateToEpoch(`1970-01-0${fromWeekDay} ${fromTime}`);
    const toWdayTs = dateToEpoch(`1970-01-0${toWeekDay} ${toTime}`);

    const boundaries = await this.retrieveSeasonBoundaries(seasonId);
    for (const b of boundaries) {
      if (dateToEpoch(b.from_) <= fromWdayTs && dateToEpoch(b.to_) >= toWdayTs) {
        return true;
      }
    }
    return false;
  }

  private async retrieveSeasonBoundaries(seasonId: number): Promise<any[]> {
    if (this.seasonBoundaryCache.has(seasonId)) {
      return this.seasonBoundaryCache.get(seasonId)!;
    }

    // Create temp view (same as PHP)
    await this.db.query(
      `CREATE OR REPLACE TEMP VIEW bsbt AS SELECT
       TIMESTAMP 'epoch ' + (EXTRACT(EPOCH FROM from_)+86400*(wday-1)) * INTERVAL '1 second' as from_,
       TIMESTAMP 'epoch ' + (EXTRACT(EPOCH FROM to_)+86400*(wday-1)) * INTERVAL '1 second' as to_
       FROM bb_season_boundary WHERE season_id=${Number(seasonId)}`,
    );

    const { rows } = await this.db.query(
      `SELECT from_, (SELECT MIN(to_) FROM bsbt AS C WHERE NOT EXISTS
         (SELECT * FROM bsbt AS D WHERE C.to_ >= D.from_ AND C.to_ < D.to_)
         AND C.to_ >= A.from_) AS to_
       FROM bsbt AS A WHERE NOT EXISTS
         (SELECT * FROM bsbt AS B WHERE A.from_ > B.from_ AND A.from_ <= B.to_)
       ORDER BY from_, to_`,
    );

    const boundaries = this.coalesceBoundariesOverDays(rows);
    this.seasonBoundaryCache.set(seasonId, boundaries);
    return boundaries;
  }

  private coalesceBoundariesOverDays(boundaries: any[]): any[] {
    const remaining = [...boundaries];
    const result: any[] = [];
    while (remaining.length) {
      const record = remaining.shift()!;
      this.coalesceBoundary(record, remaining);
      result.push(record);
    }
    return result;
  }

  private coalesceBoundary(r: any, remaining: any[]): void {
    const tsTo = dateToEpoch(r.to_);

    // BUG PRESERVED from PHP: the check `!$tsTo >= strtotime('23:59:00', $tsTo)`
    // is always false due to operator precedence, so it never returns early
    const record = remaining.shift();
    if (!record) return;

    const tsFrom = dateToEpoch(record.from_);
    const midnight = dateToEpoch(setTimeOnDate(record.from_, '00:00:59'));
    const endOfDay = dateToEpoch(setTimeOnDate(r.to_, '23:59:00'));

    if (tsFrom <= midnight && tsTo >= endOfDay) {
      r.to_ = record.to_;
      this.coalesceBoundary(r, remaining);
    } else {
      remaining.unshift(record);
    }
  }

  private checkIfResourceIsTaken(
    resource: Resource,
    startTime: Date,
    endTime: Date,
    events: Events,
  ): OverlapResult | false {
    const now = osloNow();
    const resourceId = resource.id;

    if (resource.booking_buffer_deadline) {
      now.setMinutes(now.getMinutes() + Number(resource.booking_buffer_deadline));
    }

    if (startTime <= now) {
      return { status: 3, reason: 'time_in_past', type: 'disabled' };
    }

    for (const event of events.results) {
      if (!event.resources.includes(resourceId)) continue;

      const eventStart = new Date(event.from_);
      const eventEnd = new Date(event.to_);
      const overlapBase =
        event.type === 'block' || event.status === 'NEWPARTIAL1' ? 2 : 1;
      const eventInfo = {
        id: event.id ?? null,
        type: event.type,
        status: event.status ?? null,
        from: fmtDateTimeSec(eventStart),
        to: fmtDateTimeSec(eventEnd),
      };

      const sf = fmtDateTimeSec(startTime);
      const ef = fmtDateTimeSec(endTime);
      const esf = fmtDateTimeSec(eventStart);
      const eef = fmtDateTimeSec(eventEnd);

      // Complete overlap or exact match
      if ((eventStart <= startTime && eventEnd >= endTime) || (esf === sf && eef === ef)) {
        return { status: overlapBase, reason: 'complete_overlap', type: 'complete', event: eventInfo };
      }
      // Complete containment
      if (eventStart > startTime && eventEnd < endTime) {
        return { status: overlapBase, reason: 'complete_containment', type: 'complete', event: eventInfo };
      }
      // Start overlap
      if (eventStart <= startTime && eventEnd > startTime && eventEnd < endTime) {
        return { status: overlapBase, reason: 'start_overlap', type: 'partial', event: eventInfo };
      }
      // End overlap
      if (eventStart > startTime && eventStart < endTime && eventEnd >= endTime) {
        return { status: overlapBase, reason: 'end_overlap', type: 'partial', event: eventInfo };
      }
    }
    return false;
  }

  private generateTimeSlots(
    resources: Resource[],
    events: Events,
    buildingId: number,
    _to: Date,
    stopOnEndDate: boolean,
    detailedOverlap: boolean,
  ): Record<string, TimeSlot[]> {
    const days: Record<number, string> = {
      0: 'Sunday', 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday',
      4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday',
    };

    const result: Record<string, TimeSlot[]> = {};

    for (const resource of resources) {
      if (resource.skip_timeslot) continue;
      result[resource.id] = [];

      if (!resource.simple_booking || !resource.simple_booking_start_date) continue;

      const dowStart = Number(resource.booking_dow_default_start);
      const bookingLength = Number(resource.booking_day_default_lenght);
      let bookingStart = Number(resource.booking_time_default_start);
      let bookingEnd = Number(resource.booking_time_default_end);
      const bookingTimeMinutes = resource.booking_time_minutes > 0 ? Number(resource.booking_time_minutes) : 60;

      let defaultStartHour = 8;
      let defaultStartMinute = 0;
      const defaultStartHourFallback = bookingStart > -1 ? bookingStart : 8;
      let defaultEndHour = bookingEnd > -1 ? bookingEnd : 23;
      const defaultEndHourFallback = defaultEndHour;

      // BUG PRESERVED: swap start/end for same-day
      if (bookingLength === -1 || bookingLength === 0) {
        if (resource.booking_time_default_start > -1) {
          bookingStart = Math.min(Number(resource.booking_time_default_start), Number(resource.booking_time_default_end));
        }
        if (resource.booking_time_default_end > -1) {
          bookingEnd = Math.max(Number(resource.booking_time_default_start), Number(resource.booking_time_default_end));
        }
      }

      if (bookingStart > -1) defaultStartHour = bookingStart;
      if (bookingEnd > -1) defaultEndHour = bookingEnd;
      if (bookingLength === -1) defaultEndHour--;

      const checkDate = new Date(resource.from!);
      checkDate.setHours(defaultStartHour, 0, 0, 0);

      const limitDate = stopOnEndDate ? new Date(_to) : new Date(resource.to!);

      const activeSeasons = this.getResourceSeasonsSync(resource.id, checkDate, limitDate);

      // Main slot generation loop (ported do...while)
      while (checkDate < limitDate) {
        const startTime = new Date(checkDate);

        if (defaultStartHour > defaultEndHour && (bookingLength > -1 || resource.booking_time_default_end === -1)) {
          defaultStartHour = defaultStartHourFallback;
        }

        if (startTime.getHours() > defaultEndHour) {
          startTime.setDate(startTime.getDate() + 1);
          defaultStartHour = defaultStartHourFallback;
        }

        // Day-of-week filter
        if (dowStart > -1) {
          const currentDow = startTime.getDay();
          if (dowStart !== currentDow || (dowStart === 7 && currentDow === 0)) {
            // Advance to next matching day
            const target = dowStart === 7 ? 0 : dowStart;
            let diff = target - currentDow;
            if (diff <= 0) diff += 7;
            startTime.setDate(startTime.getDate() + diff);
          }
        }

        startTime.setHours(defaultStartHour, defaultStartMinute, 0, 0);

        const endTime = new Date(startTime);
        if (bookingLength > -1) {
          endTime.setDate(endTime.getDate() + bookingLength);
        }

        if (bookingEnd > -1 && bookingLength > -1) {
          endTime.setHours(bookingEnd, 0, 0, 0);
        } else if (bookingEnd > -1 && !(bookingLength > -1)) {
          endTime.setHours(
            Math.min(bookingEnd, startTime.getHours()),
            endTime.getMinutes() + bookingTimeMinutes,
            0, 0,
          );
        } else {
          endTime.setHours(
            startTime.getHours(),
            endTime.getMinutes() + bookingTimeMinutes,
            0, 0,
          );
        }

        // Advance checkDate
        checkDate.setTime(endTime.getTime());

        // Season validation (sync since we pre-fetched)
        let withinSeason = false;
        for (const seasonId of activeSeasons) {
          if (this.timespanWithinSeasonSync(seasonId, startTime, endTime)) {
            withinSeason = true;
            break;
          }
        }

        if (withinSeason) {
          const overlapResult = this.checkIfResourceIsTaken(resource, startTime, endTime, events);
          const overlapStatus = overlapResult ? overlapResult.status : overlapResult;

          const timeslot: TimeSlot = {
            when: `${fmtDateLocale(startTime)} ${fmtHM(startTime)} - ${fmtDateLocale(endTime)} ${fmtHM(endTime)}`,
            start: `${Math.floor(startTime.getTime() / 1000)}000`,
            end: `${Math.floor(endTime.getTime() / 1000)}000`,
            overlap: overlapStatus,
            start_iso: startTime.toISOString(),
            end_iso: endTime.toISOString(),
          };

          if (detailedOverlap) {
            timeslot.resource_id = resource.id;
            if (overlapResult) {
              timeslot.overlap_reason = overlapResult.reason;
              timeslot.overlap_type = overlapResult.type;
              timeslot.overlap_event = overlapResult.event;
            }
          } else {
            timeslot.applicationLink = {
              menuaction: 'bookingfrontend.uiapplication.add',
              resource_id: resource.id,
              building_id: buildingId,
              'from_[]': fmtDateTimeSec(startTime),
              'to_[]': fmtDateTimeSec(endTime),
              simple: true,
            };
          }

          result[resource.id].push(timeslot);
        }

        // Update start hour/minute for next iteration
        if (bookingLength === -1 || resource.booking_time_default_end === -1) {
          defaultStartHour = endTime.getHours();
          defaultStartMinute = endTime.getMinutes();
          if (defaultStartHour > defaultEndHourFallback) {
            defaultStartHour = defaultStartHourFallback;
          }
        }
      }
    }

    return result;
  }

  // Synchronous versions for the hot loop (caches pre-populated)
  private resourceSeasonsSyncCache = new Map<string, number[]>();

  private getResourceSeasonsSync(resourceId: number, from: Date, to: Date): number[] {
    const key = `${resourceId}_${fmtDate(from)}_${fmtDate(to)}`;
    return this.seasonCache.get(key) || [];
  }

  private timespanWithinSeasonSync(seasonId: number, from: Date, to: Date): boolean {
    const season = this.seasonDataCache.get(seasonId);
    if (!season) return false;

    const seasonFrom = new Date(season.from_).getTime();
    const seasonTo = new Date(season.to_).getTime();
    if (seasonFrom > new Date(fmtDate(from)).getTime() || seasonTo < new Date(fmtDate(to)).getTime()) {
      return false;
    }

    const SEC_DAY = 86400;
    const daysInPeriod = Math.abs(
      (new Date(fmtDate(to)).getTime() / 1000 + SEC_DAY - new Date(fmtDate(from)).getTime() / 1000) / SEC_DAY,
    );

    let fromWeekDay: number, toWeekDay: number, fromTime: string, toTime: string;
    if (daysInPeriod <= 7) {
      fromWeekDay = isoWeekday(from);
      toWeekDay = isoWeekday(to);
      fromTime = fmtTime(from);
      toTime = fmtTime(to);
    } else {
      fromWeekDay = 1; toWeekDay = 7;
      fromTime = '00:00:00'; toTime = '23:59:00';
    }

    if (fromWeekDay > toWeekDay) {
      const endOfWeek = new Date(from);
      endOfWeek.setDate(endOfWeek.getDate() + (7 - fromWeekDay));
      endOfWeek.setHours(23, 59, 0, 0);
      const startOfWeek = new Date(to);
      startOfWeek.setDate(startOfWeek.getDate() - (toWeekDay - 1));
      startOfWeek.setHours(0, 0, 0, 0);
      return this.timespanWithinSeasonSync(seasonId, from, endOfWeek)
        && this.timespanWithinSeasonSync(seasonId, startOfWeek, to);
    }

    const fromWdayTs = dateToEpoch(`1970-01-0${fromWeekDay} ${fromTime}`);
    const toWdayTs = dateToEpoch(`1970-01-0${toWeekDay} ${toTime}`);

    const boundaries = this.seasonBoundaryCache.get(seasonId) || [];
    for (const b of boundaries) {
      if (dateToEpoch(b.from_) <= fromWdayTs && dateToEpoch(b.to_) >= toWdayTs) return true;
    }
    return false;
  }

  /**
   * Pre-fetch all season data needed for the slot generation loop.
   * This converts the async DB calls into cached sync lookups.
   */
  async prefetchSeasonData(
    resources: Resource[],
    _from: Date,
    limitDate: Date,
  ): Promise<void> {
    for (const resource of resources) {
      if (resource.skip_timeslot || !resource.simple_booking) continue;
      const from = resource.from || _from;
      const to = resource.to || limitDate;
      const seasons = await this.getResourceSeasons(
        resource.id, fmtDate(from), fmtDate(to),
      );
      for (const seasonId of seasons) {
        await this.retrieveSeasonBoundaries(seasonId);
        // Also prefetch season data
        if (!this.seasonDataCache.has(seasonId)) {
          const { rows } = await this.db.query(
            'SELECT id, from_, to_ FROM bb_season WHERE id = $1',
            [seasonId],
          );
          this.seasonDataCache.set(seasonId, rows[0] || null);
        }
      }
    }
  }

  private monthShifter(aDate: Date, months: number): Date {
    const now = osloNow();

    const startOfMonth = new Date(aDate);
    startOfMonth.setDate(1);
    startOfMonth.setHours(0, 0, 0, 0);

    if (startOfMonth > now && months > 0) months--;

    const checkLimit = new Date(aDate);
    checkLimit.setHours(23, 59, 59, 0);
    // last day of this month
    checkLimit.setMonth(checkLimit.getMonth() + 1, 0);
    if (checkLimit > now && months > 0) months--;

    const result = new Date(aDate);
    result.setMonth(result.getMonth() + months);
    // last day of that month
    result.setMonth(result.getMonth() + 1, 0);
    result.setHours(23, 59, 59, 0);
    return result;
  }
}

// --- Utility functions ---

function toOsloDate(dateStr: string): Date {
  return new Date(dateStr + 'T00:00:00');
}

function osloNow(): Date {
  return new Date();
}

function fmtDate(d: Date): string {
  return d.toISOString().slice(0, 10);
}

function fmtTime(d: Date): string {
  return `${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}`;
}

function fmtHM(d: Date): string {
  return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

function fmtDateTime(d: Date): string {
  return `${fmtDate(d)} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

function fmtDateTimeSec(d: Date): string {
  return `${fmtDate(d)} ${fmtTime(d)}`;
}

function fmtDateLocale(d: Date): string {
  return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}-${d.getFullYear()}`;
}

function pad2(n: number): string {
  return n < 10 ? '0' + n : String(n);
}

function isoWeekday(d: Date): number {
  // PHP's N format: 1=Monday..7=Sunday
  const dow = d.getDay();
  return dow === 0 ? 7 : dow;
}

function dateToEpoch(s: string): number {
  return new Date(s).getTime() / 1000;
}

function setTimeOnDate(dateStr: string, time: string): string {
  const d = new Date(dateStr);
  const [h, m, s] = time.split(':').map(Number);
  d.setHours(h, m, s || 0, 0);
  return d.toISOString();
}
