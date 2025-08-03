<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;

/**
 * @ORM\Entity
 * @ORM\Table(name="bb_group")
 * @OA\Schema(
 *      schema="Group",
 *      type="object",
 *      title="Group",
 *      description="Group model for Norwegian municipalities booking system",
 *      required={"id", "name", "organization_id", "active"}
 * )
 * @Exclude
 */
class Group
{
    use SerializableTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Expose
     * @Short
     * @OA\Property(description="Unique identifier for the group", type="integer", example=1)
     */
    public $id;

    /**
     * @ORM\Column(type="string", length=150, nullable=false)
     * @Expose
     * @Short
     * @EscapeString(mode="default")
     * @OA\Property(description="Name of the group", type="string", maxLength=150, example="Youth Football Team")
     */
    public $name;

    /**
     * @ORM\Column(type="integer", nullable=false)
     * @Expose
     * @Short
     * @OA\Property(description="Organization ID this group belongs to", type="integer", example=1)
     */
    public $organization_id;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Parent group ID for hierarchical structure", type="integer", nullable=true, example=null)
     */
    public $parent_id;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Expose
     * @OA\Property(description="Description of the group", type="string", nullable=true, example="Youth football team for players aged 12-16")
     */
    public $description;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Activity ID reference", type="integer", nullable=true, example=5)
     */
    public $activity_id;

    /**
     * @ORM\Column(type="string", length=11, nullable=true)
     * @Expose
     * @Short
     * @OA\Property(description="Short name/abbreviation for the group", type="string", maxLength=11, nullable=true, example="YouthFB")
     */
    public $shortname;

    /**
     * @ORM\Column(type="integer", nullable=false)
     * @Expose
     * @Short
     * @OA\Property(description="Whether the group is active (1=active, 0=inactive)", type="integer", enum={0, 1}, example=1)
     */
    public $active;

    /**
     * @ORM\Column(type="integer", nullable=false)
     * @Expose
     * @Short
     * @OA\Property(description="Whether to show in public portal (1=visible, 0=hidden)", type="integer", enum={0, 1}, example=1)
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