<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *      schema="ArticleMapping",
 *      type="object",
 *      title="ArticleMapping",
 *      description="Article mapping linking an article category item to billing codes",
 * )
 * @Exclude
 */
class ArticleMapping
{
    use SerializableTrait;

    /** @OA\Property(type="integer") @Expose */
    public $id;

    /** @OA\Property(type="integer") @Expose */
    public $article_cat_id;

    /** @OA\Property(type="integer") @Expose */
    public $article_id;

    /** @OA\Property(type="string") @Expose */
    public $article_name;

    /** @OA\Property(type="string") @Expose */
    public $article_cat_name;

    /** @OA\Property(type="string") @Expose */
    public $article_code;

    /** @OA\Property(type="string") @Expose */
    public $unit;

    /** @OA\Property(type="integer") @Expose */
    public $tax_code;

    /** @OA\Property(type="string", nullable=true) @Expose */
    public $tax_code_name;

    /** @OA\Property(type="integer", nullable=true) @Expose */
    public $group_id;

    /** @OA\Property(type="string", nullable=true) @Expose */
    public $article_group;

    /** @OA\Property(type="integer", nullable=true) @Expose */
    public $owner_id;

    /** @OA\Property(type="number", format="float", nullable=true) @Expose */
    public $default_price;

    /** @OA\Property(type="integer") @Expose */
    public $active = 1;

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
