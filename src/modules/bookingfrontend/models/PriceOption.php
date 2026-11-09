<?php

namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="PriceOption",
 *     type="object",
 *     title="PriceOption",
 *     description="One of the prices a citizen can choose for a resource: an active price row whose from date has been reached"
 * )
 * @Exclude
 */
class PriceOption
{
	use SerializableTrait;

	/**
	 * The bb_article_price row; sent back as the article's price_id
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $price_id;

	/**
	 * What the price is for, as the admin wrote it; may be empty
	 * @OA\Property(type="string")
	 * @Expose
	 * @EscapeString(mode="default")
	 */
	public $remark;

	/**
	 * The price category the row belongs to (bb_price_category); null when it has none.
	 * Prices of different resources with the same category can be chosen together.
	 * @OA\Property(type="integer", nullable=true)
	 * @Expose
	 */
	public $category_id;

	/**
	 * The category's name. When set, it is what the price is called instead of the remark.
	 * Read the same way as the order line's price_label, which snapshots it.
	 * @OA\Property(type="string", nullable=true)
	 * @Expose
	 * @EscapeString(mode="default")
	 */
	public $category_name;

	/**
	 * @OA\Property(type="string", description="Price before tax")
	 * @Expose
	 */
	public $ex_tax_price;

	/**
	 * @OA\Property(type="string", description="Tax amount")
	 * @Expose
	 */
	public $tax;

	/**
	 * @OA\Property(type="string", description="Price including tax")
	 * @Expose
	 */
	public $price;

	/**
	 * Marked as the default price in the admin
	 * @OA\Property(type="boolean")
	 * @Expose
	 */
	public $is_default;

	public function __construct(array $data = [])
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
