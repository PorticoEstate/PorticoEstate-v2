<?php

namespace App\modules\bookingfrontend\models;

use App\modules\booking\models\Order as BookingOrder;

/**
 * Backward-compatible alias — canonical model lives in booking module.
 *
 * @OA\Schema(schema="Order")
 * @Exclude
 */
class Order extends BookingOrder
{
}
