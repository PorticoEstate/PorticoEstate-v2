<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add simple_booking_end_date and booking_day_horizon to bb_resource';

	public function up(): void
	{
		$this->ensureColumn('bb_resource', 'simple_booking_end_date', [
			'type' => 'int', 'precision' => 8, 'nullable' => true,
		]);
		$this->ensureColumn('bb_resource', 'booking_day_horizon', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
	}
};
