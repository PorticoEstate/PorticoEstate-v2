<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Backfill bb_service.description_json from the legacy plain-text description column';

	public function up(): void
	{
		// Preconditions
		$this->assertTableExists('bb_service');
		$this->assertColumnExists('bb_service', 'description');
		$this->assertColumnExists('bb_service', 'description_json');

		// Populate description_json from the legacy text description where not already set
		$this->sql(
			"UPDATE bb_service SET description_json = jsonb_build_object('no', description) "
			. "WHERE description IS NOT NULL AND description != '' AND description_json IS NULL"
		);
	}
};
