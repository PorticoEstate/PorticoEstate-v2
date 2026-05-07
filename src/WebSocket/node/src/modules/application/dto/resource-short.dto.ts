import { Expose, Exclude, Transform } from 'class-transformer';
import { sanitizeString, parseBool } from './transforms';

/**
 * Mirrors PHP: App\modules\bookingfrontend\models\Resource (short=true serialization)
 * Class default: @Exclude — only @Expose + @Short fields are serialized.
 *
 * In the PHP Application model, resources use:
 *   @SerializeAs(type="array", of="App\modules\bookingfrontend\models\Resource", short=true)
 * So only properties with both @Expose and @Short are included.
 *
 * Note: PHP Resource.populate() always sets deactivate_calendar = 0.
 */
@Exclude()
export class ResourceShortDto {
  @Expose()
  id: number;

  @Expose()
  @Transform(({ value }) => sanitizeString(value))
  name: string;

  @Expose()
  activity_id: number | null;

  @Expose()
  active: number;

  @Expose()
  rescategory_id: number | null;

  @Expose()
  direct_booking: number | null;

  @Expose()
  simple_booking: number | null;

  @Expose()
  simple_booking_start_date: number | null;

  @Expose()
  participant_limit: number | null;

  @Expose()
  @Transform(() => false) // PHP populate() hardcodes this to 0
  deactivate_calendar: boolean;

  @Expose()
  @Transform(({ value }) => parseBool(value))
  deactivate_application: boolean;

  @Expose()
  @Transform(({ value }) => parseBool(value))
  hidden_in_frontend: boolean;

  @Expose()
  activate_prepayment: number | null;

  @Expose()
  short_description: any;

  @Expose()
  building_id: number | null;
}
