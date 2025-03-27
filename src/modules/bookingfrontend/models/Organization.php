<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;

/**
 * @ORM\Entity
 * @ORM\Table(name="bb_organization")
 * @OA\Schema(
 *      schema="Organization",
 *      type="object",
 *      title="Organization",
 *      description="Organization model",
 * )
 * @Exclude
 */
class Organization
{
    use SerializableTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Expose
     * @Short
     * @OA\Property(description="Unique identifier for the organization", type="integer")
     */
    public $id;

    /**
     * @ORM\Column(type="string", length=150)
     * @Expose
     * @Short
     * @EscapeString(mode="default")
     * @OA\Property(description="Name of the organization", type="string", maxLength=150)
     */
    public $name;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Organization number", type="string", maxLength=50, nullable=true)
     */
    public $organization_number;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Homepage of the organization", type="string", nullable=true)
     */
    public $homepage;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Phone number of the organization", type="string", maxLength=50, nullable=true)
     */
    public $phone;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Email of the organization", type="string", maxLength=255, nullable=true)
     */
    public $email;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Care of address", type="string", maxLength=255, nullable=true)
     */
    public $co_address;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Street address", type="string", maxLength=255, nullable=true)
     */
    public $street;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Zip code", type="string", maxLength=50, nullable=true)
     */
    public $zip_code;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="City", type="string", maxLength=255, nullable=true)
     */
    public $city;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="District", type="string", maxLength=255, nullable=true)
     */
    public $district;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Activity ID", type="integer", nullable=true)
     */
    public $activity_id;

    /**
     * @ORM\Column(type="integer")
     * @Expose
     * @Short
     * @OA\Property(description="Whether to show in portal", type="integer")
     */
    public $show_in_portal;

    public function __construct($data = [])
    {
        if (!empty($data))
        {
            $this->populate($data);
        }
    }

    public function populate(array $data)
    {
        foreach ($data as $key => $value)
        {
            if (property_exists($this, $key))
            {
                $this->$key = $value;
            }
        }
    }
}