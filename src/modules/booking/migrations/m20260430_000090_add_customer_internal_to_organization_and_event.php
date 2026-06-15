<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add customer_internal column to bb_organization and bb_event';

	public function up(): void
	{
		$this->ensureColumn('bb_organization', 'customer_internal', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 1,
		]);
		$this->ensureColumn('bb_event', 'customer_internal', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 1,
		]);
	}
};
