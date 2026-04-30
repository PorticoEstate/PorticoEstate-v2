<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add booking_time_minutes column to bb_resource';

	public function up(): void
	{
		$this->ensureColumn('bb_resource', 'booking_time_minutes', [
			'type' => 'int', 'precision' => 4, 'nullable' => true, 'default' => -1,
		]);
	}
};
