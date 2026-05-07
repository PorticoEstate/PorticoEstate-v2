import { Expose, Exclude, Transform } from 'class-transformer';

/**
 * Mirrors PHP: App\modules\bookingfrontend\models\Document
 * Class default: @Exclude — only @Expose fields are serialized.
 *
 * PHP Document constructor parses metadata JSON and extracts focal_point_x/y.
 * owner_type is explicitly @Exclude in PHP.
 */
@Exclude()
export class DocumentDto {
  @Expose()
  id: number;

  @Expose()
  name: string;

  @Expose()
  description: string;

  @Expose()
  category: string;

  @Expose()
  owner_id: number;

  @Expose()
  @Transform(({ value }) => {
    if (typeof value === 'string') {
      try { return JSON.parse(value); } catch { return null; }
    }
    return value ?? null;
  })
  metadata: any;

  @Expose()
  @Transform(({ obj }) => {
    const meta = typeof obj.metadata === 'string'
      ? (() => { try { return JSON.parse(obj.metadata); } catch { return null; } })()
      : obj.metadata;
    return meta?.focal_point?.x ?? null;
  })
  focal_point_x: number | null;

  @Expose()
  @Transform(({ obj }) => {
    const meta = typeof obj.metadata === 'string'
      ? (() => { try { return JSON.parse(obj.metadata); } catch { return null; } })()
      : obj.metadata;
    return meta?.focal_point?.y ?? null;
  })
  focal_point_y: number | null;
}
