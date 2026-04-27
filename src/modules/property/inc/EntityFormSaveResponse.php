<?php

namespace App\modules\property\inc;

/**
 * Represents the legacy UI action to take after entity form save handling.
 */
class EntityFormSaveResponse
{
	/**
	 * @param string $type One of json|edit|redirect-edit|redirect-index.
	 * @param array $payload JSON payload or route parameters.
	 * @param array $values Form values used when re-rendering edit.
	 */
	public function __construct(
		public string $type,
		public array $payload = [],
		public array $values = []
	) {
	}
}