import { Expose, Exclude, Transform } from 'class-transformer';
import { formatOsloTimestamp } from './transforms';

/**
 * Mirrors PHP: App\modules\bookingfrontend\models\helper\Date
 * Class default: @Exclude — only @Expose fields are serialized.
 * PHP applies @Timestamp on from_ and to_ (ISO 8601, Europe/Oslo).
 */
@Exclude()
export class DateDto {
  @Expose()
  @Transform(({ value }) => formatOsloTimestamp(value))
  from_: string;

  @Expose()
  @Transform(({ value }) => formatOsloTimestamp(value))
  to_: string;

  @Expose()
  id: number;
}
