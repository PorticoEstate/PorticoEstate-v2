<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add primary keys to bb_booking_resource and bb_season_resource';

	public function up(): void
	{
		if (!$this->constraintExists('bb_booking_resource', 'bb_booking_resource_pkey')) {
			$this->sql("ALTER TABLE bb_booking_resource ADD PRIMARY KEY (booking_id, resource_id)");
		}

		if (!$this->constraintExists('bb_season_resource', 'bb_season_resource_pkey')) {
			$this->sql("ALTER TABLE bb_season_resource ADD PRIMARY KEY (season_id, resource_id)");
		}
	}
};
