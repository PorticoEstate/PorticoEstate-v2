<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add building_name column to bb_application';

	public function up(): void
	{
		$this->ensureColumn('bb_application', 'building_name', [
			'type' => 'varchar',
			'precision' => '50',
			'nullable' => false,
			'default' => 'changeme',
		]);

		// Populate building_name from related building via application resources
		$this->sql("UPDATE bb_application SET building_name = b2.name FROM bb_building b2 WHERE EXISTS (SELECT 1 FROM bb_building b, bb_application a, bb_application_resource ar, bb_resource r WHERE a.id = ar.application_id AND ar.resource_id = r.id AND r.building_id = b.id AND b2.id = b.id AND bb_application.id = a.id) AND bb_application.building_name = 'changeme'");
	}
};
