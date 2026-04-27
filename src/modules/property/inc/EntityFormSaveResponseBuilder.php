<?php

namespace App\modules\property\inc;

/**
 * Builds the legacy UI response decision for save() without performing side effects.
 */
class EntityFormSaveResponseBuilder
{
	/**
	 * Build the response for a validation or exception error path.
	 *
	 * @param bool $isJson Whether the request expects JSON.
	 * @param array $receipt Current receipt payload.
	 * @param array $values Current form values for edit rerender.
	 * @return EntityFormSaveResponse
	 */
	public function error(bool $isJson, array $receipt, array $values = []): EntityFormSaveResponse
	{
		if ($isJson)
		{
			return new EntityFormSaveResponse('json', [
				'status' => 'error',
				'receipt' => $receipt,
			]);
		}

		return new EntityFormSaveResponse('edit', [], $values);
	}

	/**
	 * Build the response for a successful save.
	 *
	 * @param bool $isJson Whether the request expects JSON.
	 * @param array $receipt Save receipt payload.
	 * @param array $values Current saved values.
	 * @param int $originalId Entity id from the request before save.
	 * @param int $entityId Current entity id.
	 * @param int $catId Current category id.
	 * @param string $type Current type.
	 * @return EntityFormSaveResponse
	 */
	public function success(
		bool $isJson,
		array $receipt,
		array $values,
		int $originalId,
		int $entityId,
		int $catId,
		string $type
	): EntityFormSaveResponse {
		if ($isJson)
		{
			return new EntityFormSaveResponse('json', [
				'status' => 'saved',
				'id' => $receipt['id'],
				'receipt' => $receipt,
			]);
		}

		if (!empty($values['apply']))
		{
			if ($originalId || (!empty($receipt['id'])))
			{
				$_id = !empty($receipt['id']) ? $receipt['id'] : $originalId;

				return new EntityFormSaveResponse('redirect-edit', [
					'id' => $_id,
					'entity_id' => $entityId,
					'cat_id' => $catId,
					'type' => $type,
				]);
			}

			return new EntityFormSaveResponse('edit', [], $values);
		}

		return new EntityFormSaveResponse('redirect-index', [
			'entity_id' => $entityId,
			'cat_id' => $catId,
			'type' => $type,
		]);
	}
}