<?php


namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Article",
 *     type="object",
 *     title="Article",
 *     description="Article model for resources and services"
 * )
 * @Exclude
 */
class Article
{
	use SerializableTrait;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $id;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $article_id;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $parent_mapping_id;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $resource_id;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 * @EscapeString(mode="default")
	 */
	public $name;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $unit;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $tax_code;

	/**
	 * @OA\Property(type="number", format="float")
	 * @Expose
	 */
	public $tax_percent;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $group_id;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 * @EscapeString(mode="default")
	 */
	public $article_remark;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 * @EscapeString(mode="default")
	 */
	public $article_group_name;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 * @EscapeString(mode="default")
	 */
	public $article_group_remark;

	/**
	 * @OA\Property(type="number", format="float")
	 * @Expose
	 */
	public $ex_tax_price;

	/**
	 * @OA\Property(type="number", format="float")
	 * @Expose
	 */
	public $tax;

	/**
	 * @OA\Property(type="number", format="float")
	 * @Expose
	 */
	public $price;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 * @EscapeString(mode="default")
	 */
	public $price_remark;

	/**
	 * @OA\Property(type="number", format="float")
	 * @Expose
	 */
	public $unit_price;

	/**
	 * @OA\Property(type="number", format="float")
	 * @Expose
	 */
	public $selected_quantity;

	/**
	 * @OA\Property(type="number", format="float")
	 * @Expose
	 */
	public $selected_sum;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $selected_article_quantity;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $mandatory;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $lang_unit;

	/**
	 * Constructor initializes the article with provided data
	 *
	 * @param array $data Initial data for the article
	 */
	public function __construct(array $data = [])
	{
		if (!empty($data))
		{
			$this->populate($data);
		}
	}

	/**
	 * Populates object properties from an array
	 *
	 * @param array $data The data to populate from
	 */
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

	/**
	 * Calculate the price including tax
	 *
	 * @return float Price including tax
	 */
	public function calculatePriceWithTax(): float
	{
		return (float)$this->ex_tax_price * (1 + ((float)$this->tax_percent / 100));
	}

	/**
	 * Calculate total amount based on quantity
	 *
	 * @return float Total amount
	 */
	public function calculateTotal(): float
	{
		return (float)$this->price * (float)$this->selected_quantity;
	}

	/**
	 * Format a number to string with 2 decimal places
	 *
	 * @param float $value Number to format
	 * @return string Formatted number
	 */
	public function formatNumber(float $value): string
	{
		return number_format($value, 2, '.', '');
	}

	/**
	 * Generate the selected_article_quantity string
	 *
	 * @return string The formatted string for selected_article_quantity
	 */
	public function generateSelectedArticleQuantity(): string
	{
		$parentId = $this->parent_mapping_id ?? 'null';
		return "{$this->id}_{$this->selected_quantity}_{$this->tax_code}_{$this->ex_tax_price}_{$parentId}";
	}
}