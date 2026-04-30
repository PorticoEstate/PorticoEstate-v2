<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop address column and add street, zip_code, city, district to bb_building';

	public function up(): void
	{
		$this->dropColumn('bb_building', 'address');

		$this->ensureColumn('bb_building', 'street', ['type' => 'varchar', 'precision' => '255', 'nullable' => false, 'default' => '']);
		$this->ensureColumn('bb_building', 'zip_code', ['type' => 'varchar', 'precision' => '255', 'nullable' => false, 'default' => '']);
		$this->ensureColumn('bb_building', 'city', ['type' => 'varchar', 'precision' => '255', 'nullable' => false, 'default' => '']);
		$this->ensureColumn('bb_building', 'district', ['type' => 'varchar', 'precision' => '255', 'nullable' => false, 'default' => '']);
	}
};
