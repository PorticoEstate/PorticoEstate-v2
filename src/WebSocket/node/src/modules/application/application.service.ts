import { Injectable, Logger } from '@nestjs/common';
import { DatabaseService } from '../../database/database.service';

interface ApplicationDate {
  id: number;
  from_: string;
  to_: string;
}

interface ApplicationResource {
  id: number;
  name: string;
  building_id: number | null;
  [key: string]: any;
}

interface OrderLine {
  order_id: number;
  article_mapping_id: number;
  quantity: number;
  unit_price: number;
  name: string;
  unit: string;
  [key: string]: any;
}

interface Order {
  order_id: number;
  sum: number;
  lines: OrderLine[];
}

interface ArticleOrder {
  id: number;
  quantity: number;
  parent_id: number | null;
  name: string;
  unit: string;
  article_cat_id: number;
  article_id: number;
  unit_price: number;
  tax_code: number | null;
  tax_percent: number | null;
}

interface AgeGroup {
  id: number;
  name: string;
  sort: number;
  male: number;
  female: number;
  [key: string]: any;
}

interface Document {
  id: number;
  name: string;
  category: number;
  [key: string]: any;
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

          return {
            ...app,
            dates,
            resources,
            orders,
            articles,
            agegroups,
            audience,
            documents,
          };
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

  private async fetchDates(applicationId: number): Promise<ApplicationDate[]> {
    const { rows } = await this.db.query(
      `SELECT * FROM bb_application_date
       WHERE application_id = $1
       ORDER BY from_`,
      [applicationId],
    );
    return rows;
  }

  private async fetchResources(
    applicationId: number,
  ): Promise<ApplicationResource[]> {
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

  private async fetchOrders(applicationId: number): Promise<Order[]> {
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

    // Group lines by order
    const orderMap = new Map<number, Order>();
    for (const row of rows) {
      const orderId = row.order_id;
      if (!orderMap.has(orderId)) {
        orderMap.set(orderId, {
          order_id: orderId,
          sum: 0,
          lines: [],
        });
      }
      const order = orderMap.get(orderId)!;
      order.lines.push(row);
      order.sum += parseFloat(row.amount || '0');
    }

    return Array.from(orderMap.values());
  }

  private async fetchArticles(
    applicationId: number,
  ): Promise<ArticleOrder[]> {
    const { rows } = await this.db.query(
      `SELECT pol.article_mapping_id as id, pol.quantity, pol.parent_mapping_id as parent_id,
              CASE WHEN r.name IS NULL THEN s.name ELSE r.name END AS name,
              am.unit, am.article_cat_id, am.article_id, pol.unit_price,
              pol.tax_code, e.percent_ AS tax_percent
       FROM bb_purchase_order po
       JOIN bb_purchase_order_line pol ON po.id = pol.order_id
       JOIN bb_article_mapping am ON pol.article_mapping_id = am.id
       LEFT JOIN fm_ecomva e ON pol.tax_code = e.id
       LEFT JOIN bb_service s ON (am.article_id = s.id AND am.article_cat_id = 2)
       LEFT JOIN bb_resource r ON (am.article_id = r.id AND am.article_cat_id = 1)
       WHERE po.cancelled IS NULL AND po.application_id = $1
       ORDER BY pol.id`,
      [applicationId],
    );
    return rows;
  }

  private async fetchAgeGroups(
    applicationId: number,
  ): Promise<AgeGroup[]> {
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

  private async fetchTargetAudience(
    applicationId: number,
  ): Promise<number[]> {
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

  private async fetchDocuments(
    applicationId: number,
  ): Promise<Document[]> {
    const { rows } = await this.db.query(
      `SELECT * FROM bb_document_application
       WHERE owner_id = $1`,
      [applicationId],
    );
    return rows;
  }
}
