<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_building_cadastral_reference table';

	public function up(): void
	{
		$this->createTable('bb_building_cadastral_reference', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'building_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'municipality_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'cadastral_type' => ['type' => 'varchar', 'precision' => 8, 'nullable' => false],
				'building_number' => ['type' => 'int', 'precision' => 8, 'nullable' => true],
				'gnr' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'bnr' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'fnr' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'snr' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_building' => ['building_id' => 'id'],
			],
			'ix' => [],
			'uc' => [
				'building_number',
				['municipality_id', 'gnr', 'bnr', 'fnr', 'snr'],
			],
		]);

		$this->sql("ALTER TABLE bb_building_cadastral_reference ADD CONSTRAINT bb_building_cadastral_reference_type_check CHECK (cadastral_type IN ('BUILDING', 'PROPERTY'))");
	}
};