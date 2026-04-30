<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add external_archive_key to bb_application (archive config sections are legacy setup)';

	public function up(): void
	{
		// The custom_config/Locations setup (common_archive, public360 sections) is legacy
		// admin configuration that should be handled separately. Only the schema change is migrated.
		$this->ensureColumn('bb_application', 'external_archive_key', [
			'type' => 'varchar', 'precision' => '64', 'nullable' => true,
		]);
	}
};
