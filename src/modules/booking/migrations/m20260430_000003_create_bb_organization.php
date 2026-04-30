<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_organization table';

	public function up(): void
	{
		$this->createTable('bb_organization', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'homepage' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);
	}
};
