<?php

namespace App\modules\bookingfrontend\models\helper;

use App\modules\booking\models\Date as BookingDate;

/**
 * Backward-compatible alias — canonical model lives in booking module.
 *
 * @OA\Schema(schema="Date")
 * @Exclude
 */
class Date extends BookingDate
{
}
