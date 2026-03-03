<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *      schema="HospitalityArticle",
 *      type="object",
 *      title="HospitalityArticle",
 *      description="Article available in a hospitality, references bb_article_mapping",
 * )
 * @Exclude
 */
class HospitalityArticle
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
     * @OA\Property(type="integer", nullable=true)
     * @Expose
     */
    public $article_group_id;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $article_mapping_id;

    /**
     * @OA\Property(type="string", nullable=true)
     * @Expose
     */
    public $description;

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

    /**
     * @OA\Property(type="number", format="float", nullable=true)
     * @Expose
     */
    public $override_price;

    /**
     * @OA\Property(type="integer", nullable=true)
     * @Expose
     */
    public $override_tax_code;

    // -- Computed fields from bb_article_mapping / bb_article_price --

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $article_name;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $unit;

    /**
     * @OA\Property(type="number", format="float", nullable=true)
     * @Expose
     */
    public $base_price;

    /**
     * @OA\Property(type="integer", nullable=true)
     * @Expose
     */
    public $base_tax_code;

    /**
     * Effective price: override_price if set, otherwise base_price
     * @OA\Property(type="number", format="float")
     * @Expose
     */
    public $effective_price;

    /**
     * Effective tax code: override_tax_code if set, otherwise base_tax_code
     * @OA\Property(type="integer")
     * @Expose
     */
    public $effective_tax_code;

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

        $this->effective_price = $this->override_price ?? $this->base_price;
        $this->effective_tax_code = $this->override_tax_code ?? $this->base_tax_code;
    }
}
