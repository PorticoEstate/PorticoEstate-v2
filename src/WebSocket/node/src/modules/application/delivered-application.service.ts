import { Injectable, Logger } from '@nestjs/common';
import { plainToInstance, instanceToPlain } from 'class-transformer';
import { createHash } from 'crypto';
import { DatabaseService } from '../../database/database.service';
import { ApplicationDto, OrderDto, OrderLineDto } from './dto';

/**
 * Remove keys whose value is null/undefined, matching PHP SerializableTrait.
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

export interface DeliveredApplicationsPage {
  applications: any[];
  totalCount: number;
  offset: number;
  limit: number;
  hasMore: boolean;
}

@Injectable()
export class DeliveredApplicationService {
  private readonly logger = new Logger(DeliveredApplicationService.name);

  constructor(private readonly db: DatabaseService) {}

  /**
   * Fetch delivered applications for a user, paginated.
   *
   * Access modes:
   * 1. SSN-based: personal applications where customer_ssn matches
   * 2. Organization: applications for orgs where user is an active delegate
   * 3. Secret-based: single application accessed by secret token
   *
   * Mirrors PHP: ApplicationRepository::getApplicationsBySsnAndOrganizations
   */
  async getDeliveredApplications(opts: {
    ssn?: string;
    includeOrganizations?: boolean;
    secret?: string;
    offset?: number;
    limit?: number;
  }): Promise<DeliveredApplicationsPage> {
    if (!this.db.isConnected()) {
      this.logger.error('Cannot get delivered applications — DB not connected');
      return { applications: [], totalCount: 0, offset: 0, limit: 0, hasMore: false };
    }

    const { ssn, includeOrganizations = true, secret, offset = 0, limit = 50 } = opts;

    try {
      // Secret-based single app access
      if (secret && !ssn) {
        return this.getBySecret(secret);
      }

      if (!ssn) {
        return { applications: [], totalCount: 0, offset: 0, limit: 0, hasMore: false };
      }

      // Build WHERE conditions — all apps the user can access
      const conditions: string[] = [];
      const params: any[] = [];
      let paramIndex = 1;

      // Personal: matched by SSN
      conditions.push(`customer_ssn = $${paramIndex}`);
      params.push(ssn);
      paramIndex++;

      // Organization: matched by org id or org number
      if (includeOrganizations) {
        const orgs = await this.getActiveOrganizations(ssn);

        if (orgs.orgIds.length > 0) {
          const placeholders = orgs.orgIds.map((_, i) => `$${paramIndex + i}`).join(',');
          conditions.push(`customer_organization_id IN (${placeholders})`);
          params.push(...orgs.orgIds);
          paramIndex += orgs.orgIds.length;
        }

        if (orgs.orgNumbers.length > 0) {
          const placeholders = orgs.orgNumbers.map((_, i) => `$${paramIndex + i}`).join(',');
          conditions.push(`customer_organization_number IN (${placeholders})`);
          params.push(...orgs.orgNumbers);
          paramIndex += orgs.orgNumbers.length;
        }
      }

      const whereSql = `WHERE (${conditions.join(' OR ')}) AND status != 'NEWPARTIAL1'`;
      const baseSql = `SELECT * FROM bb_application ${whereSql}`;

      // Get total count (cheap — no relation joins)
      const countSql = `SELECT COUNT(*) as total FROM bb_application ${whereSql}`;
      const { rows: countRows } = await this.db.query(countSql, params);
      const totalCount = parseInt(countRows[0]?.total || '0', 10);

      if (totalCount === 0 || offset >= totalCount) {
        return { applications: [], totalCount, offset, limit, hasMore: false };
      }

      // Fetch the page
      const pageSql = `${baseSql} ORDER BY created DESC LIMIT $${paramIndex} OFFSET $${paramIndex + 1}`;
      params.push(limit, offset);

      const { rows: appRows } = await this.db.query(pageSql, params);

      // Hydrate relations in parallel for this batch only
      const applications = await this.hydrateApplications(appRows, ssn);

      return {
        applications,
        totalCount,
        offset,
        limit,
        hasMore: offset + applications.length < totalCount,
      };
    } catch (err: any) {
      this.logger.error(`Error fetching delivered applications: ${err.message}`);
      return { applications: [], totalCount: 0, offset, limit, hasMore: false };
    }
  }

  /**
   * Fetch a single application by secret token.
   */
  private async getBySecret(secret: string): Promise<DeliveredApplicationsPage> {
    const { rows } = await this.db.query(
      `SELECT *, 'personal' as application_type FROM bb_application
       WHERE secret = $1 AND status != 'NEWPARTIAL1'`,
      [secret],
    );

    if (rows.length === 0) {
      return { applications: [], totalCount: 0, offset: 0, limit: 1, hasMore: false };
    }

    const applications = await this.hydrateApplications(rows);

    return {
      applications,
      totalCount: 1,
      offset: 0,
      limit: 1,
      hasMore: false,
    };
  }

  /**
   * Fetch a single application by ID with access control.
   * Access: secret token, SSN match, or org delegate match.
   * Mirrors PHP: ApplicationHelper::canViewApplication
   */
  async getApplicationById(opts: {
    id: number;
    ssn?: string;
    secret?: string;
  }): Promise<{ application: any | null; error?: string }> {
    if (!this.db.isConnected()) {
      return { application: null, error: 'Database not connected' };
    }

    const { id, ssn, secret } = opts;

    try {
      const { rows } = await this.db.query(
        `SELECT * FROM bb_application WHERE id = $1`,
        [id],
      );

      if (rows.length === 0) {
        return { application: null, error: 'Application not found' };
      }

      const app = rows[0];

      // Access check (mirrors PHP ApplicationHelper::canViewApplication)
      const hasAccess = this.checkAccess(app, ssn, secret);
      if (!hasAccess) {
        return { application: null, error: 'Unauthorized' };
      }

      const applications = await this.hydrateApplications([app], ssn);
      return { application: applications[0] || null };
    } catch (err: any) {
      this.logger.error(`Error fetching application ${id}: ${err.message}`);
      return { application: null, error: err.message };
    }
  }

  /**
   * Check if access is allowed for an application.
   * Mirrors PHP: ApplicationHelper::canViewApplication
   */
  private async checkAccess(app: any, ssn?: string, secret?: string): Promise<boolean> {
    // Secret access
    if (secret && app.secret && app.secret === secret) {
      return true;
    }

    if (!ssn) return false;

    // SSN match
    if (app.customer_ssn === ssn) {
      return true;
    }

    // Organization match — check if user is a delegate for the app's org
    if (app.customer_organization_number) {
      const orgs = await this.getActiveOrganizations(ssn);
      if (app.customer_organization_id && orgs.orgIds.includes(app.customer_organization_id)) {
        return true;
      }
      if (orgs.orgNumbers.includes(app.customer_organization_number)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Encode SSN to SHA1 format used in bb_delegate table.
   * Matches PHP: '{SHA1}' . base64_encode(sha1($ssn))
   * PHP sha1() returns a hex string, then base64_encode encodes that hex string.
   */
  private encodeSsn(ssn: string): string {
    const hexHash = createHash('sha1').update(ssn).digest('hex');
    const base64 = Buffer.from(hexHash).toString('base64');
    return `{SHA1}${base64}`;
  }

  /**
   * Get active organization delegates for a user by SSN.
   * Mirrors PHP: MinId2::get_breg_orgs — queries bb_delegate with SHA1-encoded SSN,
   * plus bb_organization by raw SSN.
   */
  private async getActiveOrganizations(ssn: string): Promise<{ orgIds: number[]; orgNumbers: string[] }> {
    try {
      const encodedSsn = this.encodeSsn(ssn);

      // Match PHP's UNION query: delegates (by encoded SSN) + personal orgs (by raw SSN)
      const { rows } = await this.db.query(
        `SELECT DISTINCT org_id, organization_number FROM (
           SELECT o.id as org_id, o.organization_number
           FROM bb_delegate d
           JOIN bb_organization o ON d.organization_id = o.id
           WHERE d.active = 1 AND o.active = 1 AND d.ssn = $1
           UNION
           SELECT o.id as org_id, o.organization_number
           FROM bb_organization o
           WHERE o.active = 1 AND o.customer_ssn = $2 AND o.customer_identifier_type = 'ssn'
         ) as t`,
        [encodedSsn, ssn],
      );

      const orgIds: number[] = [];
      const orgNumbers: string[] = [];

      for (const row of rows) {
        if (row.org_id) orgIds.push(row.org_id);
        if (row.organization_number) orgNumbers.push(row.organization_number);
      }

      return { orgIds, orgNumbers };
    } catch (err: any) {
      this.logger.error(`Error fetching organizations for SSN: ${err.message}`);
      return { orgIds: [], orgNumbers: [] };
    }
  }

  /**
   * Hydrate a batch of application rows with their relations.
   * Also enriches missing customer_organization_name from bb_organization.
   */
  private async hydrateApplications(appRows: any[], userSsn?: string): Promise<any[]> {
    // Collect org numbers that need name enrichment
    const needsOrgName = appRows.filter(
      a => (!a.customer_organization_name || a.customer_organization_name === '') && a.customer_organization_number
    );
    const orgNameMap = new Map<string, string>();
    if (needsOrgName.length > 0) {
      const orgNumbers = [...new Set(needsOrgName.map(a => a.customer_organization_number))];
      const placeholders = orgNumbers.map((_, i) => `$${i + 1}`).join(',');
      const { rows: orgRows } = await this.db.query(
        `SELECT organization_number, name FROM bb_organization WHERE organization_number IN (${placeholders})`,
        orgNumbers,
      );
      for (const row of orgRows) {
        orgNameMap.set(row.organization_number, row.name);
      }
    }

    return Promise.all(
      appRows.map(async (app) => {
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

        // Pre-process: enrich missing org name, convert pg Date objects
        const preprocessed: Record<string, any> = { ...app };
        if ((!preprocessed.customer_organization_name || preprocessed.customer_organization_name === '')
            && preprocessed.customer_organization_number) {
          preprocessed.customer_organization_name = orgNameMap.get(preprocessed.customer_organization_number) || '';
        }
        for (const key of ['created', 'modified', 'frontend_modified']) {
          if (preprocessed[key] instanceof Date) {
            preprocessed[key] = dateToDbString(preprocessed[key]);
          }
        }
        if (preprocessed.recurring_info != null && typeof preprocessed.recurring_info !== 'string') {
          preprocessed.recurring_info = JSON.stringify(preprocessed.recurring_info);
        }

        const plain = { ...preprocessed, dates, resources, orders, articles, agegroups, audience, documents };
        const dto = plainToInstance(ApplicationDto, plain, { excludeExtraneousValues: true });
        const serialized = instanceToPlain(dto, { excludeExtraneousValues: true });

        const result = stripNulls(serialized);
        // Derive application_type from the row's own data
        const isOrg = app.customer_identifier_type === 'organization_number'
          || (app.customer_organization_id != null)
          || (app.customer_organization_number && app.customer_organization_number !== '');
        result.application_type = isOrg ? 'organization' : 'personal';
        return result;
      }),
    );
  }

  // --- Relation fetchers (same as ApplicationService) ---

  private async fetchDates(applicationId: number): Promise<any[]> {
    const { rows } = await this.db.query(
      `SELECT * FROM bb_application_date WHERE application_id = $1 ORDER BY from_`,
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

    const orderMap = new Map<number, { order_id: number; sum: number; lines: any[] }>();
    for (const row of rows) {
      const lineId = row.id;
      if (!orderMap.has(lineId)) {
        orderMap.set(lineId, { order_id: lineId, sum: 0, lines: [] });
      }
      const order = orderMap.get(lineId)!;
      const lineDto = plainToInstance(OrderLineDto, row, { excludeExtraneousValues: true });
      order.lines.push(instanceToPlain(lineDto, { excludeExtraneousValues: true }));
      order.sum += (parseFloat(row.amount || '0')) + (parseFloat(row.tax || '0'));
    }

    return Array.from(orderMap.values()).map((order) => {
      const dto = plainToInstance(OrderDto, order, { excludeExtraneousValues: true });
      return stripNulls(instanceToPlain(dto, { excludeExtraneousValues: true }));
    });
  }

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
      `SELECT * FROM bb_document_application WHERE owner_id = $1`,
      [applicationId],
    );
    return rows;
  }
}
