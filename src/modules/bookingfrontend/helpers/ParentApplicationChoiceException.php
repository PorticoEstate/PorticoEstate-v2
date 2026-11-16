<?php

namespace App\modules\bookingfrontend\helpers;

/**
 * Checkout cannot accept the choice of parent application: a building's parent is not one of the applications in
 * that checkout named by its id, or it is in another building, or building_parent_ids is not a map of building id to
 * application id. Checkout answers it with 400 and changes nothing.
 */
class ParentApplicationChoiceException extends \InvalidArgumentException
{
}
