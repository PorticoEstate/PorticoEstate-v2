<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="CompletedReservation",
 *     type="object",
 *     title="CompletedReservation",
 *     description="CompletedReservation model"
 * )
 * @Exclude
 */
class CompletedReservation
{
    use SerializableTrait;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $id;

    /**
     * @OA\Property(type="string", maxLength=70)
     * @Expose
     */
    public $reservation_type;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $reservation_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $season_id;

    /**
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $cost;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     * @Timestamp
     */
    public $from_;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     * @Timestamp
     */
    public $to_;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $organization_id;

    /**
     * @OA\Property(type="string", maxLength=70)
     * @Expose
     */
    public $customer_type;

    /**
     * @OA\Property(type="string", maxLength=9)
     * @Expose
     */
    public $customer_organization_number;

    /**
     * @OA\Property(type="string", maxLength=12)
     * @Expose
     */
    public $customer_ssn;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $description;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $building_name;

    /**
     * @OA\Property(type="string", maxLength=35)
     * @Expose
     */
    public $article_description;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $building_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $exported;

    /**
     * @OA\Property(type="string", maxLength=255)
     * @Expose
     */
    public $customer_identifier_type;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $export_file_id;

    /**
     * @OA\Property(type="string", maxLength=255)
     * @Expose
     */
    public $invoice_file_order_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $customer_number;

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
