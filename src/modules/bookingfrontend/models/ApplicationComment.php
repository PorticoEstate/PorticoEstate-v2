<?php

namespace App\modules\bookingfrontend\models;

use App\modules\booking\models\ApplicationComment as BookingApplicationComment;

/**
 * Backward-compatible alias — canonical model lives in booking module.
 *
 * @OA\Schema(schema="ApplicationComment")
 * @Exclude
 */
class ApplicationComment extends BookingApplicationComment
{
}
