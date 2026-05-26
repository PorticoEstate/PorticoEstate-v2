<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *      schema="HospitalityOrder",
 *      type="object",
 *      title="HospitalityOrder",
 *      description="Hospitality order bound to an application",
 * )
 * @Exclude
 */
class HospitalityOrder
{
    use SerializableTrait;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DELIVERED = 'delivered';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELLED,
        self::STATUS_DELIVERED,
    ];

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $application_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $hospitality_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $location_resource_id;

    /**
     * @OA\Property(type="string", enum={"pending", "confirmed", "cancelled", "delivered"})
     * @Expose
     */
    public $status = self::STATUS_PENDING;

    /**
     * @OA\Property(type="string", nullable=true)
     * @Expose
     */
    public $comment;

    /**
     * @OA\Property(type="string", nullable=true)
     * @Expose
     */
    public $special_requirements;

    /**
     * When the order should be fulfilled/served (UTC ISO-8601 string)
     * @OA\Property(type="string", nullable=true, example="2026-03-04T18:30:00.000Z")
     * @Expose
     */
    public $serving_time_iso;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     */
    public $created;

    /**
     * @OA\Property(type="string", format="date-time")
     * @Expose
     */
    public $modified;

    /**
     * @OA\Property(type="integer", nullable=true)
     * @Expose
     */
    public $created_by;

    /**
     * @OA\Property(type="integer", nullable=true)
     * @Expose
     */
    public $modified_by;

    // -- Computed fields --

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $hospitality_name;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $location_name;

    /**
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $total_amount;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/HospitalityOrderLine"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\booking\models\HospitalityOrderLine")
     */
    public $lines;

    /**
     * @OA\Property(type="array", @OA\Items(type="object"))
     * @Expose
     */
    public $changelog;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->populate($data);
        }
    }

    public function populate(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'lines' && is_array($value)) {
                $this->lines = array_map(
                    fn($lineData) => new HospitalityOrderLine($lineData),
                    $value
                );
            } elseif (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::VALID_STATUSES, true);
    }
}
