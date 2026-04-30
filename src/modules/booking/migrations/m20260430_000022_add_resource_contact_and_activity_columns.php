<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add address, phone, email, and activity_id columns to bb_resource';

	public function up(): void
	{
		$this->ensureColumn('bb_resource', 'address', [
			'type' => 'varchar',
			'precision' => 1000,
			'nullable' => false,
			'default' => '',
		]);

		$this->ensureColumn('bb_resource', 'phone', [
			'type' => 'varchar',
			'precision' => 250,
			'nullable' => false,
			'default' => '',
		]);

		$this->ensureColumn('bb_resource', 'email', [
			'type' => 'varchar',
			'precision' => 250,
			'nullable' => false,
			'default' => '',
		]);

		$this->ensureColumn('bb_resource', 'activity_id', [
			'type' => 'int',
			'nullable' => true,
		]);

		if (!$this->constraintExists('bb_resource', 'bb_resource_activity_id_fkey')) {
			$this->sql("ALTER TABLE bb_resource ADD CONSTRAINT bb_resource_activity_id_fkey FOREIGN KEY (activity_id) REFERENCES bb_activity(id)");
		}
	}
};
