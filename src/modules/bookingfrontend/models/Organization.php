<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Organization",
 *     type="object",
 *     title="Organization",
 *     description="Organization model"
 * )
 * @Exclude
 */
class Organization
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
    public $organization_number;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $active;

    /**
     * @OA\Property(type="string")
     * @Expose
    */
    public $name;

    /**
     * @OA\Property(type="string")
     * @Expose
    */
    public $homepage;

    /**
     * @OA\Property(type="string")
     * @Expose
    */
    public $phone;

    /**
     * @OA\Property(type="string")
     * @Expose
    */
    public $email;

    /**
     * @OA\Property(type="string")
     * @Expose
    */
    public $street;
    
    /**
     * @OA\Property(type="number")
     * @Expose
    */
    public $zip_code;

    /**
     * @OA\Property(type="string")
     * @Expose
    */
    public $district;

    /**
     * @OA\Property(type="string")
     * @Expose
    */
    public $city;

    /**
     * @OA\Property(type="string")
     * @Expose
    */
    public $activity_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $customer_identifier_type;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $customer_organization_number;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $customer_ssn;
}
