import { Injectable, Logger } from '@nestjs/common';
import { plainToInstance, instanceToPlain } from 'class-transformer';
import { DatabaseService } from '../../database/database.service';
import { ApplicationDto, OrderDto, OrderLineDto } from './dto';

/**
 * Remove keys whose value is null/undefined, matching PHP SerializableTrait
 * which skips properties where `$value !== null`.
 */
function stripNulls(obj: any): any {
  if (Array.isArray(obj)) return obj.map(stripNulls);
  if (obj && typeof obj === 'object') {
    const out: any = {};
    for (const [k, v] of Object.entries(obj)) {
      if (v === null || v === undefined) continue;
      out[k] = stripNulls(v);
    }
    return out;
  }
  return obj;
}

/**
 * Convert Date objects to raw DB-style strings (e.g. "2026-05-07 11:31:28.717852").
 * The pg driver auto-parses timestamp columns into JS Date objects, but PHP
 * passes them through as raw strings since there's no @Timestamp on these fields.
 */
function dateToDbString(value: any): string {
  if (!(value instanceof Date)) return value;
  const pad = (n: number, len = 2) => String(n).padStart(len, '0');
  const y = value.getFullYear();
  const mo = pad(value.getMonth() + 1);
  const d = pad(value.getDate());
  const h = pad(value.getHours());
  const mi = pad(value.getMinutes());
  const s = pad(value.getSeconds());
  const ms = pad(value.getMilliseconds(), 3);
  return `${y}-${mo}-${d} ${h}:${mi}:${s}.${ms}`;
}

@Injectable()
export class ApplicationService {
  private readonly logger = new Logger(ApplicationService.name);

  constructor(private readonly db: DatabaseService) {}

  async getPartialApplications(sessionId: string): Promise<any[]> {
    if (!this.db.isConnected()) {
      this.logger.error('Cannot get partial applications — DB not connected');
      return [];
    }

    try {
      const { rows: applications } = await this.db.query(
        `SELECT * FROM bb_application
         WHERE status = 'NEWPARTIAL1' AND session_id = $1`,
        [sessionId],
      );

      const results = await Promise.all(
        applications.map(async (app) => {
          const [dates, resources, orders, articles, agegroups, audience, documents] =
            await Promise.all([
              this.fetchDates(app.id),
              this.fetchResources(app.id),
              this.fetchOrders(app.id),
              this.fetchArticles(app.id),
              this.fetchAgeGroups(app.id),
              this.fetchTargetAudience(app.id),
              this.fetchDocuments(app.id),
            ]);

          // Pre-process fields that pg driver auto-converts but PHP keeps as raw strings
          const preprocessed: Record<string, any> = { ...app };
          if (preprocessed.created instanceof Date) {
            preprocessed.created = dateToDbString(preprocessed.created);
          }
          if (preprocessed.modified instanceof Date) {
            preprocessed.modified = dateToDbString(preprocessed.modified);
          }
          if (preprocessed.frontend_modified instanceof Date) {
            preprocessed.frontend_modified = dateToDbString(preprocessed.frontend_modified);
          }
          // pg driver auto-parses JSON/JSONB — PHP keeps it as a raw string
          if (preprocessed.recurring_info != null && typeof preprocessed.recurring_info !== 'string') {
            preprocessed.recurring_info = JSON.stringify(preprocessed.recurring_info);
          }

          const plain = {
            ...preprocessed,
            dates,
            resources,
            orders,
            articles,
            agegroups,
            audience,
            documents,
          };

          const dto = plainToInstance(ApplicationDto, plain, {
            excludeExtraneousValues: true,
          });
          const serialized = instanceToPlain(dto, { excludeExtraneousValues: true });
          return stripNulls(serialized);
        }),
      );

      this.logger.log(
        `Retrieved ${results.length} partial applications for session ${sessionId.substring(0, 8)}...`,
      );
      return results;
    } catch (err: any) {
      this.logger.error(
        `Error fetching partial applications: ${err.message}`,
      );
      return [];
    }
  }

  private async fetchDates(applicationId: number): Promise<any[]> {
    const { rows } = await this.db.query(
      `SELECT * FROM bb_application_date
       WHERE application_id = $1
       ORDER BY from_`,
      [applicationId],
    );
    return rows;
  }

  private async fetchResources(applicationId: number): Promise<any[]> {
    const { rows } = await this.db.query(
      `SELECT r.*, br.building_id
       FROM bb_resource r
       JOIN bb_application_resource ar ON r.id = ar.resource_id
       LEFT JOIN bb_building_resource br ON r.id = br.resource_id
       WHERE ar.application_id = $1`,
      [applicationId],
    );
    return rows;
  }

  /**
   * Fetch and group order lines into Order objects.
   *
   * PHP quirk: `SELECT po.*, pol.*` causes `pol.id` to overwrite `po.id`,
   * then PHP groups by `$row['id']` — effectively grouping by the line's PK,
   * so each line becomes its own "order". We replicate this behavior.
   *
   * PHP sum: $line->amount + $line->tax per line.
   */
  private async fetchOrders(applicationId: number): Promise<any[]> {
    const { rows } = await this.db.query(
      `SELECT po.*, pol.*, am.unit,
              CASE WHEN r.name IS NULL THEN s.name ELSE r.name END AS name
       FROM bb_purchase_order po
       JOIN bb_purchase_order_line pol ON po.id = pol.order_id
       JOIN bb_article_mapping am ON pol.article_mapping_id = am.id
       LEFT JOIN bb_service s ON (am.article_id = s.id AND am.article_cat_id = 2)
       LEFT JOIN bb_resource r ON (am.article_id = r.id AND am.article_cat_id = 1)
       WHERE po.cancelled IS NULL AND po.application_id = $1
       ORDER BY pol.id`,
      [applicationId],
    );

    // PHP groups by $row['id'] which is pol.id (line PK) due to column collision.
    // Each line becomes its own "order" with order_id = pol.id.
    const orderMap = new Map<number, { order_id: number; sum: number; lines: any[] }>();
    for (const row of rows) {
      // pol.id overwrites po.id in PHP's SELECT po.*, pol.*
      const lineId = row.id;
      if (!orderMap.has(lineId)) {
        orderMap.set(lineId, { order_id: lineId, sum: 0, lines: [] });
      }
      const order = orderMap.get(lineId)!;

      // Serialize line through OrderLineDto
      const lineDto = plainToInstance(OrderLineDto, row, { excludeExtraneousValues: true });
      order.lines.push(instanceToPlain(lineDto, { excludeExtraneousValues: true }));

      order.sum += (parseFloat(row.amount || '0')) + (parseFloat(row.tax || '0'));
    }

    // Serialize each order through OrderDto, strip nulls
    return Array.from(orderMap.values()).map((order) => {
      const dto = plainToInstance(OrderDto, order, { excludeExtraneousValues: true });
      return stripNulls(instanceToPlain(dto, { excludeExtraneousValues: true }));
    });
  }

  /**
   * Fetch articles matching PHP ArticleRepository::fetchArticlesForApplication —
   * returns only {id, quantity, parent_id} with int casting.
   */
  private async fetchArticles(applicationId: number): Promise<any[]> {
    const { rows } = await this.db.query(
      `SELECT pol.article_mapping_id as id, pol.quantity, pol.parent_mapping_id as parent_id
       FROM bb_purchase_order po
       JOIN bb_purchase_order_line pol ON po.id = pol.order_id
       WHERE po.cancelled IS NULL AND po.application_id = $1
       ORDER BY pol.id`,
      [applicationId],
    );
    return rows.map((row) => ({
      id: parseInt(row.id, 10),
      quantity: parseInt(row.quantity, 10),
      parent_id: row.parent_id ? parseInt(row.parent_id, 10) : null,
    }));
  }

  private async fetchAgeGroups(applicationId: number): Promise<any[]> {
    const { rows } = await this.db.query(
      `SELECT ag.*, aag.male, aag.female
       FROM bb_application_agegroup aag
       JOIN bb_agegroup ag ON aag.agegroup_id = ag.id
       WHERE aag.application_id = $1
       ORDER BY ag.sort`,
      [applicationId],
    );
    return rows;
  }

  private async fetchTargetAudience(applicationId: number): Promise<number[]> {
    const { rows } = await this.db.query(
      `SELECT ta.id
       FROM bb_application_targetaudience ata
       JOIN bb_targetaudience ta ON ata.targetaudience_id = ta.id
       WHERE ata.application_id = $1
       ORDER BY ta.sort`,
      [applicationId],
    );
    return rows.map((r) => r.id);
  }

  private async fetchDocuments(applicationId: number): Promise<any[]> {
    const { rows } = await this.db.query(
      `SELECT * FROM bb_document_application
       WHERE owner_id = $1`,
      [applicationId],
    );
    return rows;
  }
}
