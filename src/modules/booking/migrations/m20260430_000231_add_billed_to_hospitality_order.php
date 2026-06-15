<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add billed column to bb_hospitality_order';

	public function up(): void
	{
		$this->assertTableExists('bb_hospitality_order');

		$this->ensureColumn('bb_hospitality_order', 'billed', [
			'type' => 'int',
			'precision' => 4,
			'nullable' => false,
			'default' => 0,
		]);
	}
};
