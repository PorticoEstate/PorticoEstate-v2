<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add building_name column to bb_completed_reservation';

	public function up(): void
	{
		$this->ensureColumn('bb_completed_reservation', 'building_name', ['type' => 'text', 'nullable' => false, 'default' => '']);
	}
};
