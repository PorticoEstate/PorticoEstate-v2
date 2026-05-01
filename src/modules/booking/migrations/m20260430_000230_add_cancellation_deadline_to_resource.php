<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add cancellation_deadline_value and cancellation_deadline_unit to bb_resource';

	public function up(): void
	{
		$this->ensureColumn('bb_resource', 'cancellation_deadline_value', [
			'type' => 'int',
			'precision' => 4,
			'nullable' => true,
			'default' => 0,
		]);

		$this->ensureColumn('bb_resource', 'cancellation_deadline_unit', [
			'type' => 'varchar',
			'precision' => 10,
			'nullable' => true,
			'default' => 'hours',
		]);
	}
};
