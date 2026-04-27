<?php

namespace App\modules\property\inc;

/**
 * Carries the persisted entity state back to the legacy save adapter.
 */
class EntityFormSaveResult
{
	/**
	 * @param array $receipt Legacy save receipt returned by bo->save().
	 * @param array $values Mutated form values including generated id.
	 */
	public function __construct(
		public array $receipt,
		public array $values
	) {
	}
}