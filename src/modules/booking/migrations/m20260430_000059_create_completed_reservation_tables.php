<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_completed_reservation and bb_completed_reservation_resource tables, add completed/cost columns';

	public function up(): void
	{
		$this->createTable('bb_completed_reservation', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'reservation_type' => ['type' => 'varchar', 'precision' => '70', 'nullable' => false],
				'reservation_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'season_id' => ['type' => 'int', 'precision' => '4'],
				'cost' => ['type' => 'decimal', 'precision' => '10', 'scale' => '2', 'nullable' => false],
				'from_' => ['type' => 'timestamp', 'nullable' => false],
				'to_' => ['type' => 'timestamp', 'nullable' => false],
				'organization_id' => ['type' => 'int', 'precision' => '4'],
				'customer_type' => ['type' => 'varchar', 'precision' => '70', 'nullable' => false],
				'customer_organization_number' => ['type' => 'varchar', 'precision' => '9'],
				'customer_ssn' => ['type' => 'varchar', 'precision' => '12'],
				'exported' => ['type' => 'int', 'precision' => '4', 'nullable' => false, 'default' => 0],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_organization' => ['organization_id' => 'id'],
				'bb_season' => ['season_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_completed_reservation_resource', [
			'fd' => [
				'completed_reservation_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['completed_reservation_id', 'resource_id'],
			'fk' => [
				'bb_completed_reservation' => ['completed_reservation_id' => 'id'],
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->ensureColumn('bb_booking', 'completed', ['type' => 'int', 'precision' => '4', 'nullable' => false, 'default' => 0]);
		$this->ensureColumn('bb_event', 'completed', ['type' => 'int', 'precision' => '4', 'nullable' => false, 'default' => 0]);
		$this->ensureColumn('bb_allocation', 'completed', ['type' => 'int', 'precision' => '4', 'nullable' => false, 'default' => 0]);
		$this->ensureColumn('bb_booking', 'cost', ['type' => 'decimal', 'precision' => '10', 'scale' => '2', 'nullable' => false, 'default' => '0.0']);
	}
};
