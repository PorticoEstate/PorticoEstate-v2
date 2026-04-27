<?php

namespace App\modules\property\inc;

use App\modules\phpgwapi\services\Cache;
use Sanitizer;

/**
 * Maps legacy entity form request state into normalized input arrays.
 *
 * This is the first extraction step from property_uientity::_populate(),
 * keeping request parsing and session-backed location collection together
 * while the surrounding validation and persistence flow remains unchanged.
 */
class EntityFormInputMapper
{
	/**
	 * Build the base input arrays used by the legacy entity save flow.
	 *
	 * @param string $typeApp     Current app key, e.g. property.
	 * @param string $type        Entity type key.
	 * @param string $aclLocation Current ACL location.
	 * @param object $bocommon    Legacy bocommon helper with collect_locationdata().
	 * @return array{values: array, values_attribute: mixed, bypass: bool}
	 */
	public function map(string $typeApp, string $type, string $aclLocation, object $bocommon): array
	{
		$values = (array) Sanitizer::get_var('values');
		$values_attribute = Sanitizer::get_var('values_attribute');
		$bypass = (bool) Sanitizer::get_var('bypass', 'bool');

		$values['vendor_id'] = Sanitizer::get_var('vendor_id', 'int', 'POST');
		$values['vendor_name'] = Sanitizer::get_var('vendor_name', 'string', 'POST');
		$values['date'] = Sanitizer::get_var('date');

		if (!$bypass)
		{
			$insert_record = Cache::session_get('property', 'insert_record');
			$insert_record_entity = (array) Cache::session_get($typeApp, 'insert_record_values' . $aclLocation);

			if (is_array($insert_record_entity))
			{
				foreach ($insert_record_entity as $insert_value)
				{
					$insert_record['extra'][$insert_value] = $insert_value;
				}
			}

			$values = $bocommon->collect_locationdata($values, $insert_record);
		}

		return [
			'values' => $values,
			'values_attribute' => $values_attribute,
			'bypass' => $bypass,
		];
	}
}