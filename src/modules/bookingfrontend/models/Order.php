<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Order",
 *     type="object",
 *     title="Order",
 *     description="Order model"
 * )
 * @Exclude
 */
class Order
{
    use SerializableTrait;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $order_id;

    /**
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $sum;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/OrderLine"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\bookingfrontend\models\OrderLine")
     */
    public $lines;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->populate($data);
        }
    }

    public function populate(array $data)
    {
        foreach ($data as $key => $value) {
            if ($key === 'lines') {
                $this->lines = array_map(function ($lineData) {
                    return new OrderLine($lineData);
                }, $value);
            } elseif (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}