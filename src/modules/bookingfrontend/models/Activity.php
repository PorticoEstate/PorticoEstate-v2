<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;

/**
 * @ORM\Entity
 * @ORM\Table(name="bb_activity")
 * @OA\Schema(
 *      schema="Activity",
 *      type="object",
 *      title="Activity",
 *      description="Activity model",
 * )
 * @Exclude
 */
class Activity
{
    use SerializableTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Expose
     * @Short
     * @OA\Property(description="Unique identifier for the activity", type="integer")
     */
    public $id;

    /**
     * @ORM\Column(type="string", length=150)
     * @Expose
     * @Short
     * @EscapeString(mode="default")
     * @OA\Property(description="Name of the activity", type="string", maxLength=150)
     */
    public $name;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Parent activity ID", type="integer", nullable=true)
     */
    public $parent_id;

    /**
     * @ORM\Column(type="integer")
     * @Expose
     * @Short
     * @OA\Property(description="Whether the activity is active", type="integer")
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