<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add booking_limit_number and booking_limit_number_horizont to bb_resource';

	public function up(): void
	{
		$this->ensureColumn('bb_resource', 'booking_limit_number', [
			'type' => 'int', 'precision' => 4, 'nullable' => true, 'default' => -1,
		]);
		$this->ensureColumn('bb_resource', 'booking_limit_number_horizont', [
			'type' => 'int', 'precision' => 4, 'nullable' => true, 'default' => -1,
		]);
	}
};
