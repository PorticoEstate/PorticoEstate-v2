<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop legacy phpgw_booking table and create bb_building table';

	public function up(): void
	{
		$this->dropTable('phpgw_booking');

		$this->createTable('bb_building', [
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
