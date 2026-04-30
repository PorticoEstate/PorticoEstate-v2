<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_resource_e_lock table, migrate e_lock data from bb_resource, and add booking default columns';

	public function up(): void
	{
		$this->createTable('bb_resource_e_lock', [
			'fd' => [
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'e_lock_system_id' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'e_lock_resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'e_lock_name' => ['type' => 'varchar', 'precision' => 20, 'nullable' => true],
				'access_code_format' => ['type' => 'varchar', 'precision' => 20, 'nullable' => true],
				'active' => ['type' => 'int', 'nullable' => false, 'precision' => 2, 'default' => 1],
				'modified_on' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'modified_by' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['resource_id', 'e_lock_system_id', 'e_lock_resource_id'],
			'fk' => [
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		// Migrate e_lock data from bb_resource to bb_resource_e_lock if columns still exist
		if ($this->columnExists('bb_resource', 'e_lock_system_id'))
		{
			$this->sql(
				"INSERT INTO bb_resource_e_lock (resource_id, e_lock_system_id, e_lock_resource_id, modified_by)"
				. " SELECT id, e_lock_system_id, e_lock_resource_id, 0"
				. " FROM bb_resource WHERE e_lock_system_id IS NOT NULL"
				. " ON CONFLICT DO NOTHING"
			);
		}

		$this->dropColumn('bb_resource', 'e_lock_system_id');
		$this->dropColumn('bb_resource', 'e_lock_resource_id');

		$this->ensureColumn('bb_resource', 'booking_day_default_lenght', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
		$this->ensureColumn('bb_resource', 'booking_dow_default_start', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
		$this->ensureColumn('bb_resource', 'booking_dow_default_end', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
		$this->ensureColumn('bb_resource', 'booking_time_default_start', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
		$this->ensureColumn('bb_resource', 'booking_time_default_end', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
	}
};
