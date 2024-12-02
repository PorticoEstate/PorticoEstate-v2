<?php

namespace App\modules\bookingfrontend\models\helper;

use App\traits\SerializableTrait;

/**
 * Abstract base class for Schedule entities (Events, Bookings, Allocations)
 */
abstract class BaseScheduleEntity
{
    use SerializableTrait;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $active;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     */
    public $from_;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     */
    public $to_;

    /**
     * @OA\Property(type="number", format="float")
     */
    public $cost;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $completed;

    /**
     * @OA\Property(type="integer")
     */
    public $application_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $building_name;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $skip_bas;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/Resource"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\Resource", short=true)
     */
    public $resources;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->populate($data);
        }
    }

    protected function populate(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}