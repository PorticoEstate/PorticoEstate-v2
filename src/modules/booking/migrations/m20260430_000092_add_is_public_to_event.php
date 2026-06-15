<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add is_public column to bb_event';

	public function up(): void
	{
		$this->ensureColumn('bb_event', 'is_public', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 1,
		]);
	}
};
