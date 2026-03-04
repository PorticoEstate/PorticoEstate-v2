<?php

namespace App\modules\bookingfrontend\models;

use App\modules\booking\models\OrderLine as BookingOrderLine;

/**
 * Backward-compatible alias — canonical model lives in booking module.
 *
 * @OA\Schema(schema="OrderLine")
 * @Exclude
 */
class OrderLine extends BookingOrderLine
{
}
