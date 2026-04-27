<?php

namespace App\modules\property\inc;

use Sanitizer;

/**
 * Rebuilds form state needed after validation errors in the legacy entity flow.
 */
class EntityFormRehydrateService
{
	/**
	 * Restore location details and parent entity references after validation errors.
	 *
	 * @param array $values Current form values.
	 * @return array Rehydrated form values.
	 */
	public function rehydrate(array $values): array
	{
		if ($values['location'])
		{
			$bolocation = CreateObject('property.bolocation');
			$location_code = implode("-", $values['location']);
			$values['extra']['view'] = true;
			$values['location_data'] = $bolocation->read_single($location_code, $values['extra']);
		}

		if ($values['extra']['p_num'])
		{
			$values['p'][$values['extra']['p_entity_id']]['p_num'] = $values['extra']['p_num'];
			$values['p'][$values['extra']['p_entity_id']]['p_entity_id'] = $values['extra']['p_entity_id'];
			$values['p'][$values['extra']['p_entity_id']]['p_cat_id'] = $values['extra']['p_cat_id'];
			$values['p'][$values['extra']['p_entity_id']]['p_cat_name'] = Sanitizer::get_var('entity_cat_name_' . $values['extra']['p_entity_id']);
		}

		return $values;
	}
}