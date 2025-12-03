<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;

/**
 * @ORM\Entity
 * @ORM\Table(name="bb_multi_domain")
 * @OA\Schema(
 *      schema="MultiDomain",
 *      type="object",
 *      title="MultiDomain",
 *      description="Multi Domain model",
 * )
 * @Exclude
 */
class MultiDomain
{
    use SerializableTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @Expose
     * @ORM\GeneratedValue(strategy="AUTO")
     * @OA\Property(description="Unique identifier for the multi domain", type="integer")
     */
    public $id;

    /**
     * @ORM\Column(type="string", length=200)
     * @Expose
     * @EscapeString(mode="default")
     * @OA\Property(description="Name of the domain", type="string", maxLength=200)
     */
    public $name;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Expose
     * @OA\Property(description="Web service host URL", type="string", nullable=true)
     */
    public $webservicehost;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @OA\Property(description="User ID who created this domain", type="integer", nullable=true)
     */
    public $user_id;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @OA\Property(description="Entry date as timestamp", type="integer", nullable=true)
     */
    public $entry_date;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @OA\Property(description="Modified date as timestamp", type="integer", nullable=true)
     */
    public $modified_date;

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