<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_permission_season table';

	public function up(): void
	{
		$this->createTable('bb_permission_season', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'subject_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'object_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'role' => ['type' => 'varchar', 'precision' => '255', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'phpgw_accounts' => ['subject_id' => 'account_id'],
				'bb_season' => ['object_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
