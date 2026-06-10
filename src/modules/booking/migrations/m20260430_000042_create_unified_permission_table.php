<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop old permission tables and create unified bb_permission table';

	public function up(): void
	{
		$this->dropTable('bb_permission_building');
		$this->dropTable('bb_permission_resource');
		$this->dropTable('bb_permission_season');

		$this->createTable('bb_permission', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'subject_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'object_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'object_type' => ['type' => 'varchar', 'precision' => '255', 'nullable' => false],
				'role' => ['type' => 'varchar', 'precision' => '255', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'phpgw_accounts' => ['subject_id' => 'account_id'],
			],
			'ix' => [['object_id', 'object_type'], ['object_type']],
			'uc' => [],
		]);
	}
};
