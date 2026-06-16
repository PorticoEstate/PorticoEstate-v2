import { Expose, Exclude, Transform } from 'class-transformer';
import { sanitizeString } from './transforms';

/**
 * Mirrors PHP: App\modules\bookingfrontend\models\OrderLine
 * Class default: @Exclude — only @Expose fields are serialized.
 */
@Exclude()
export class OrderLineDto {
  @Expose()
  order_id: number;

  @Expose()
  status: number;

  @Expose()
  parent_mapping_id: number;

  @Expose()
  article_mapping_id: number;

  /** Article category: 1 = resource (rental), 2 = service (add-on article) */
  @Expose()
  article_cat_id: number;

  @Expose()
  quantity: number;

  @Expose()
  unit_price: number;

  @Expose()
  overridden_unit_price: number;

  @Expose()
  currency: string;

  @Expose()
  amount: number;

  @Expose()
  unit: string;

  @Expose()
  tax_code: number;

  @Expose()
  tax: number;

  @Expose()
  @Transform(({ value }) => sanitizeString(value))
  name: string;
}
