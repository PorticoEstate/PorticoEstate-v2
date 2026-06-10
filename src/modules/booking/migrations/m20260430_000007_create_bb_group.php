<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_group table';

	public function up(): void
	{
		$this->createTable('bb_group', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'organization_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_organization' => ['organization_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
