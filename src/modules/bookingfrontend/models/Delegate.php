<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Delegate",
 *     type="object",
 *     title="Delegate",
 *     description="Delegate model"
 * )
 * @Exclude
 */
class Delegate
{
    use SerializableTrait;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $name;

    /**
     * @OA\Property(type="number")
     * @Expose
     */
    public $org_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $organization_number;

    /**
     * @OA\Property(type="boolean")
     * @Expose
     */
    public $active;

    public function __construct(array $data)
    {
        $this->name = $data['orgname'] ?? null;
        $this->organization_number = $data['orgnr'] ?? null;
        $this->org_id = $data['org_id'] ?? null;
        $this->active = true;
    }
}