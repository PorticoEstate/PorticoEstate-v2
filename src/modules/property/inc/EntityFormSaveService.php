<?php

namespace App\modules\property\inc;

use App\Database\Db;
use Sanitizer;

/**
 * Persists entity form data inside the legacy transaction boundary.
 */
class EntityFormSaveService
{
	/**
	 * Save the entity and any checklist stage updates within one transaction.
	 *
	 * @param array $values Form values to persist.
	 * @param mixed $attributes Attribute payload passed to bo->save().
	 * @param string $action add|edit.
	 * @param int $entityId Current entity id.
	 * @param int $catId Current category id.
	 * @param object $bo Legacy boentity helper.
	 * @return array{receipt: array, values: array}
	 */
	public function save(array $values, $attributes, string $action, int $entityId, int $catId, object $bo): array
	{
		Db::getInstance()->transaction_begin();

		$receipt = $bo->save($values, $attributes, $action, $entityId, $catId);
		$values['id'] = $receipt['id'];
		$valuesChecklistStage = Sanitizer::get_var('values_checklist_stage');

		if ($valuesChecklistStage)
		{
			$bo->save_checklist($receipt['id'], $valuesChecklistStage, $receipt);
		}

		Db::getInstance()->transaction_commit();

		return [
			'receipt' => $receipt,
			'values' => $values,
		];
	}
}