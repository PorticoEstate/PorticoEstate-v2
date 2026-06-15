<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add location_code column to bb_building';

	public function up(): void
	{
		$this->ensureColumn('bb_building', 'location_code', [
			'type' => 'text',
			'nullable' => true,
		]);
	}
};
