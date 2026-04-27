<?php

namespace App\modules\property\inc;

use phpgw;
use Sanitizer;

/**
 * Validates entity form input during the legacy save flow.
 */
class EntityFormValidationService
{
	/**
	 * Run the legacy save-time validation rules.
	 *
	 * @param array $values Current form values.
	 * @param mixed $valuesAttribute Submitted attribute values.
	 * @param int $catId Current category id.
	 * @param int $entityId Current entity id.
	 * @param object $soadminEntity Legacy category reader.
	 * @param object $bo Legacy boentity helper for attribute metadata enrichment.
	 * @return array{values: array, values_attribute: mixed, errors: array}
	 */
	public function validate(
		array $values,
		$valuesAttribute,
		int $catId,
		int $entityId,
		object $soadminEntity,
		object $bo
	): array {
		$errors = [];

		if (!$catId)
		{
			$errors[] = ['msg' => lang('Please select entity type !')];

			return [
				'values' => $values,
				'values_attribute' => $valuesAttribute,
				'errors' => $errors,
			];
		}

		$category = $soadminEntity->read_single_category($entityId, $catId);

		if ($category['org_unit'])
		{
			$values['extra']['org_unit_id'] = Sanitizer::get_var('org_unit_id', 'int');
			$values['org_unit_id'] = $values['extra']['org_unit_id'];
			$values['org_unit_name'] = Sanitizer::get_var('org_unit_name', 'string');
		}

		if (phpgw::is_repost())
		{
			$errors[] = ['msg' => lang('Hmm... looks like a repost!')];
		}

		if ((!$values['location'] && !$values['p']) && isset($category['location_level']) && $category['location_level'])
		{
			$errors[] = ['msg' => lang('Please select a location !')];
		}

		if (isset($valuesAttribute) && is_array($valuesAttribute))
		{
			$firstAttribute = current($valuesAttribute);
			if (empty($firstAttribute['datatype']))
			{
				$bo->get_attribute_information($valuesAttribute);
			}

			foreach ($valuesAttribute as $attribute)
			{
				if ($attribute['nullable'] != 1 && (!$attribute['value'] && !$values['extra'][$attribute['name']]))
				{
					$errors[] = ['msg' => lang('Please enter value for attribute %1', $attribute['input_text'])];
				}

				if (isset($attribute['value']) && $attribute['value'] && $attribute['datatype'] == 'I' && !ctype_digit($attribute['value']))
				{
					$errors[] = ['msg' => lang('Please enter integer for attribute %1', $attribute['input_text'])];
				}
			}
		}

		return [
			'values' => $values,
			'values_attribute' => $valuesAttribute,
			'errors' => $errors,
		];
	}
}