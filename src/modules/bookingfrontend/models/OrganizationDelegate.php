<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;

/**
 * @ORM\Entity
 * @ORM\Table(name="bb_delegate")
 * @OA\Schema(
 *      schema="OrganizationDelegate",
 *      type="object",
 *      title="Organization Delegate",
 *      description="Organization delegate model for Norwegian municipalities booking system",
 *      required={"id", "name", "organization_id", "active"}
 * )
 * @Exclude
 */
class OrganizationDelegate
{
    use SerializableTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Expose
     * @Short
     * @OA\Property(description="Unique identifier for the delegate", type="integer", example=1)
     */
    public $id;

    /**
     * @ORM\Column(type="string", length=150, nullable=false)
     * @Expose
     * @Short
     * @EscapeString(mode="default")
     * @OA\Property(description="Name of the delegate", type="string", maxLength=150, example="John Doe")
     */
    public $name;

    /**
     * @ORM\Column(type="integer", nullable=false)
     * @Expose
     * @Short
     * @OA\Property(description="Organization ID this delegate belongs to", type="integer", example=1)
     */
    public $organization_id;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Email address of the delegate", type="string", maxLength=255, nullable=true, format="email", example="john.doe@example.com")
     */
    public $email;

    /**
     * @ORM\Column(type="string", length=115, nullable=true)
     * @OA\Property(description="Social Security Number (Norwegian format, 11 digits) - RESTRICTED: Not exposed to frontend for security", type="string", maxLength=115, nullable=true, pattern="^[0-9]{11}$", example="12345678901")
     */
    public $ssn;

    /**
     * @Expose
     * @Short
     * @OA\Property(description="Whether this delegate is the current logged-in user", type="boolean", example=false)
     */
    public $is_self;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Phone number of the delegate", type="string", maxLength=50, nullable=true, example="+47 12 34 56 78")
     */
    public $phone;

    /**
     * @ORM\Column(type="integer", nullable=false)
     * @Expose
     * @Short
     * @OA\Property(description="Whether the delegate is active (1=active, 0=inactive)", type="integer", enum={0, 1}, example=1)
     */
    public $active;

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