import { Expose, Exclude, Type } from 'class-transformer';
import { OrderLineDto } from './order-line.dto';

/**
 * Mirrors PHP: App\modules\bookingfrontend\models\Order
 * Class default: @Exclude — only @Expose fields are serialized.
 *
 * PHP Order model uses:
 *   @SerializeAs(type="array", of="App\modules\bookingfrontend\models\OrderLine")
 * for the lines property.
 */
@Exclude()
export class OrderDto {
  @Expose()
  order_id: number;

  @Expose()
  sum: number;

  @Expose()
  @Type(() => OrderLineDto)
  lines: OrderLineDto[];
}
