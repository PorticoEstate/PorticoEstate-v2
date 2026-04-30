<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_contact_person table and add contact columns to bb_group';

	public function up(): void
	{
		$this->createTable('bb_contact_person', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'ssn' => ['type' => 'int', 'precision' => '4', 'nullable' => true],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'homepage' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'phone' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'email' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'description' => ['type' => 'varchar', 'precision' => '1000', 'nullable' => false, 'default' => ''],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);

		$this->ensureColumn('bb_group', 'contact_primary', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_group', 'contact_secondary', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);
	}
};
