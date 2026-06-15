<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add direct_booking_season_id column to bb_resource';

	public function up(): void
	{
		$this->ensureColumn('bb_resource', 'direct_booking_season_id', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
	}
};
