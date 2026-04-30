<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add description, address, phone, and email columns to bb_building';

	public function up(): void
	{
		$this->ensureColumn('bb_building', 'description', [
			'type' => 'varchar',
			'precision' => 1000,
			'nullable' => false,
			'default' => '',
		]);

		$this->ensureColumn('bb_building', 'address', [
			'type' => 'varchar',
			'precision' => 250,
			'nullable' => false,
			'default' => '',
		]);

		$this->ensureColumn('bb_building', 'phone', [
			'type' => 'varchar',
			'precision' => 50,
			'nullable' => false,
			'default' => '',
		]);

		$this->ensureColumn('bb_building', 'email', [
			'type' => 'varchar',
			'precision' => 50,
			'nullable' => false,
			'default' => '',
		]);
	}
};
