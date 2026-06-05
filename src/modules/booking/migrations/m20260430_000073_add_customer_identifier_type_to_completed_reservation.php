<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add customer_identifier_type column to bb_completed_reservation';

	public function up(): void
	{
		$this->ensureColumn('bb_completed_reservation', 'customer_identifier_type', [
			'type' => 'varchar',
			'precision' => '255',
			'nullable' => true,
		]);
	}
};
