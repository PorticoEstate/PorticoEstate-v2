<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *      schema="HospitalityArticleGroup",
 *      type="object",
 *      title="HospitalityArticleGroup",
 *      description="Group to organize articles within a hospitality",
 * )
 * @Exclude
 */
class HospitalityArticleGroup
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
    public $hospitality_id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $name;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $sort_order = 0;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $active = 1;

    // -- Computed fields --

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
