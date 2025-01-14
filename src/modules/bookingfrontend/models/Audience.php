<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Audience",
 *     type="object",
 *     title="Audience",
 *     description="Target Audience model"
 * )
 * @Exclude
 */
class Audience
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
    public $activity_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $name;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $sort;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $description;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $active;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->populate($data);
        }
    }

    public function populate(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}