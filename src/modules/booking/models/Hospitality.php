<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *      schema="Hospitality",
 *      type="object",
 *      title="Hospitality",
 *      description="Hospitality (bevertning) entity linked to a resource",
 * )
 * @Exclude
 */
class Hospitality
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
    public $resource_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $name;

    /**
     * @OA\Property(type="string", nullable=true)
     * @Expose
     */
    public $description;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $active = 1;

    /**
     * @OA\Property(type="integer", description="Whether food can be delivered to remote locations")
     * @Expose
     */
    public $remote_serving_enabled = 0;

    /**
     * @OA\Property(type="integer", description="Whether pre-ordering to the main resource is allowed without booking it")
     * @Expose
     */
    public $allow_delivery = 0;

    /**
     * @OA\Property(type="integer", nullable=true)
     * @Expose
     */
    public $order_by_time_value;

    /**
     * @OA\Property(type="string", nullable=true, enum={"hours", "days"})
     * @Expose
     */
    public $order_by_time_unit;

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
     * @OA\Property(type="integer")
     * @Expose
     */
    public $created_by;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $modified_by;

    // -- Computed fields --

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $resource_name;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/HospitalityRemoteLocation"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\booking\models\HospitalityRemoteLocation")
     */
    public $remote_locations;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/HospitalityArticleGroup"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\booking\models\HospitalityArticleGroup")
     */
    public $article_groups;

    /**
     * @OA\Property(type="array", @OA\Items(ref="#/components/schemas/HospitalityArticle"))
     * @Expose
     * @SerializeAs(type="array", of="App\modules\booking\models\HospitalityArticle")
     */
    public $articles;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->populate($data);
        }
    }

    public function populate(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
