<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add active column to bb_building';

	public function up(): void
	{
		$this->ensureColumn('bb_building', 'active', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 1,
		]);
	}
};
