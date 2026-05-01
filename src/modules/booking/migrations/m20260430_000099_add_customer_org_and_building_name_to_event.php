<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add customer_organization_id, customer_organization_name, and building_name to bb_event';

	public function up(): void
	{
		$this->ensureColumn('bb_event', 'customer_organization_id', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_event', 'customer_organization_name', [
			'type' => 'varchar',
			'precision' => '50',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_event', 'building_name', [
			'type' => 'varchar',
			'precision' => '50',
			'nullable' => false,
			'default' => 'changeme',
		]);

		// Populate building_name from related building
		$this->sql("UPDATE bb_event SET building_name = b2.name FROM bb_building b2 WHERE EXISTS (SELECT 1 FROM bb_event e, bb_event_resource er, bb_resource r, bb_building b WHERE e.id = er.event_id AND er.resource_id = r.id AND r.building_id = b.id AND b2.id = b.id AND bb_event.id = e.id) AND bb_event.building_name = 'changeme'");
	}
};
