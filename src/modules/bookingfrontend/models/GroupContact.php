<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;

/**
 * @ORM\Entity
 * @ORM\Table(name="bb_group_contact")
 * @OA\Schema(
 *      schema="GroupContact",
 *      type="object",
 *      title="GroupContact",
 *      description="Group contact model for Norwegian municipalities booking system",
 *      required={"id", "name", "group_id"}
 * )
 * @Exclude
 */
class GroupContact
{
    use SerializableTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Expose
     * @Short
     * @OA\Property(description="Unique identifier for the group contact", type="integer", example=1)
     */
    public $id;

    /**
     * @ORM\Column(type="string", length=150, nullable=false)
     * @Expose
     * @Short
     * @EscapeString(mode="default")
     * @OA\Property(description="Name of the contact person", type="string", maxLength=150, example="John Doe")
     */
    public $name;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Email address of the contact", type="string", nullable=true, example="john.doe@example.com")
     */
    public $email;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Phone number of the contact", type="string", nullable=true, example="+47 12345678")
     */
    public $phone;

    /**
     * @ORM\Column(type="integer", nullable=false)
     * @Expose
     * @Short
     * @OA\Property(description="Group ID this contact belongs to", type="integer", example=1)
     */
    public $group_id;

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