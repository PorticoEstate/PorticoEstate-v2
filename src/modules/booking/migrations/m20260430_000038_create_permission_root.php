<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_permission_root table';

	public function up(): void
	{
		$this->createTable('bb_permission_root', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'subject_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'phpgw_accounts' => ['subject_id' => 'account_id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
