<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *      schema="HospitalityRemoteLocation",
 *      type="object",
 *      title="HospitalityRemoteLocation",
 *      description="Remote delivery location for a hospitality",
 * )
 * @Exclude
 */
class HospitalityRemoteLocation
{
    use SerializableTrait;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $hospitality_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $resource_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $active = 1;

    // -- Computed fields --

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $resource_name;

    /**
     * True when this location is an effective link (active row on an active hospitality) to a
     * resource that has direct booking: an existing conflict the admin UI warns about.
     *
     * @OA\Property(type="boolean", nullable=true)
     * @Expose
     */
    public $direct_booking_conflict;

    /**
     * @OA\Property(type="integer", nullable=true)
     * @Expose
     */
    public $building_id;

    /**
     * @OA\Property(type="string", nullable=true)
     * @Expose
     */
    public $building_name;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->populate($data);
        }
    }

    public function populate(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
