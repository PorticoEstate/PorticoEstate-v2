<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="OrderLine",
 *     type="object",
 *     title="OrderLine",
 *     description="OrderLine model"
 * )
 * @Exclude
 */
class OrderLine
{
    use SerializableTrait;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $order_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $status;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $parent_mapping_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $article_mapping_id;

    /**
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $quantity;

    /**
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $unit_price;

    /**
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $overridden_unit_price;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $currency;

    /**
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $amount;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $unit;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $tax_code;

    /**
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $tax;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $name;

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