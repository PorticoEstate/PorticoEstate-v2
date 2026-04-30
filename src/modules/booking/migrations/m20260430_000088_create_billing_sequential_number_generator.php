<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_billing_sequential_number_generator table with initial rows';

	public function up(): void
	{
		$this->createTable('bb_billing_sequential_number_generator', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'text', 'nullable' => false],
				'value' => ['type' => 'int', 'precision' => '4', 'nullable' => false, 'default' => 0],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => ['name'],
		]);

		// Insert initial rows if they don't exist
		$this->sql("INSERT INTO bb_billing_sequential_number_generator (name, value) SELECT 'internal', 0 WHERE NOT EXISTS (SELECT 1 FROM bb_billing_sequential_number_generator WHERE name = 'internal')");
		$this->sql("INSERT INTO bb_billing_sequential_number_generator (name, value) SELECT 'external', 34500000 WHERE NOT EXISTS (SELECT 1 FROM bb_billing_sequential_number_generator WHERE name = 'external')");
	}
};
