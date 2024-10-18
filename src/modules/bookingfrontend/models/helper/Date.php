<?php

namespace App\modules\bookingfrontend\models\helper;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Date",
 *     type="object",
 *     title="Date",
 *     description="Date model for application"
 * )
 * @Exclude
 */
class Date
{
    use SerializableTrait;

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
     * @OA\Property(type="integer")
     * @Expose
     */
    public $id;

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