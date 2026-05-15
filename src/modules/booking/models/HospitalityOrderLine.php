<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *      schema="HospitalityOrderLine",
 *      type="object",
 *      title="HospitalityOrderLine",
 *      description="Line item in a hospitality order",
 * )
 * @Exclude
 */
class HospitalityOrderLine
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
    public $order_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $hospitality_article_id;

    /**
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $quantity = 1.0;

    /**
     * Snapshotted at order time
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $unit_price;

    /**
     * Snapshotted at order time
     * @OA\Property(type="integer")
     * @Expose
     */
    public $tax_code;

    /**
     * quantity * unit_price
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $amount;

    /**
     * @OA\Property(type="string", nullable=true)
     * @Expose
     */
    public $comment;

    // -- Computed fields --

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $article_name;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $unit;

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
