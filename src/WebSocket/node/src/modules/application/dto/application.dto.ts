import { Expose, Exclude, Type, Transform } from 'class-transformer';
import { sanitizeString } from './transforms';
import { DateDto } from './date.dto';
import { ResourceShortDto } from './resource-short.dto';
import { OrderDto } from './order.dto';
import { DocumentDto } from './document.dto';

/**
 * Mirrors PHP: App\modules\bookingfrontend\models\Application
 * Class default: @Exclude — only @Expose fields are serialized.
 *
 * Nested relations use @Type for class-transformer to apply their own DTOs:
 * - dates: DateDto (PHP @SerializeAs array of Date)
 * - resources: ResourceShortDto (PHP @SerializeAs array of Resource, short=true)
 * - orders: OrderDto (PHP @SerializeAs array of Order)
 * - documents: DocumentDto (PHP @SerializeAs array of Document)
 * - articles: plain objects {id, quantity, parent_id} — no model in PHP
 * - agegroups: raw rows — no model serialization in PHP
 * - audience: number[] — no model serialization in PHP
 */
@Exclude()
export class ApplicationDto {
  @Expose()
  id: number;

  @Expose()
  id_string: string;

  @Expose()
  active: number;

  @Expose()
  display_in_dashboard: number;

  @Expose()
  type: string;

  @Expose()
  status: string;

  @Expose()
  secret: string;

  @Expose()
  created: string;

  @Expose()
  modified: string;

  @Expose()
  @Transform(({ value }) => sanitizeString(value))
  building_name: string;

  @Expose()
  building_id: number;

  @Expose()
  frontend_modified: string | null;

  @Expose()
  owner_id: number;

  @Expose()
  case_officer_id: number | null;

  @Expose()
  activity_id: number;

  @Expose()
  customer_identifier_type: string;

  @Expose()
  customer_ssn: string | null;

  @Expose()
  customer_organization_number: string | null;

  @Expose()
  @Transform(({ value }) => sanitizeString(value))
  name: string;

  @Expose()
  organizer: string;

  @Expose()
  homepage: string | null;

  @Expose()
  description: string | null;

  @Expose()
  equipment: string | null;

  @Expose()
  contact_name: string;

  @Expose()
  contact_email: string;

  @Expose()
  contact_phone: string;

  @Expose()
  responsible_street: string;

  @Expose()
  responsible_zip_code: string;

  @Expose()
  responsible_city: string;

  @Expose()
  session_id: string | null;

  @Expose()
  agreement_requirements: string | null;

  @Expose()
  external_archive_key: string | null;

  @Expose()
  customer_organization_name: string | null;

  @Expose()
  customer_organization_id: number | null;

  @Expose()
  recurring_info: string | null;

  // --- Relations ---

  @Expose()
  @Type(() => DateDto)
  dates: DateDto[];

  @Expose()
  @Type(() => ResourceShortDto)
  resources: ResourceShortDto[];

  @Expose()
  @Type(() => OrderDto)
  orders: OrderDto[];

  @Expose()
  @Type(() => DocumentDto)
  documents: DocumentDto[];

  /** PHP returns raw agegroup rows — no model serialization */
  @Expose()
  agegroups: any[];

  /** PHP returns number[] of target audience IDs */
  @Expose()
  audience: number[];

  /** PHP ArticleRepository returns only {id, quantity, parent_id} */
  @Expose()
  articles: any[];
}
