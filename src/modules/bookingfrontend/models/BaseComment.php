<?php

namespace App\modules\bookingfrontend\models;

use App\modules\booking\models\BaseComment as BookingBaseComment;

/**
 * Backward-compatible alias — canonical model lives in booking module.
 *
 * @Exclude
 */
abstract class BaseComment extends BookingBaseComment
{
}
