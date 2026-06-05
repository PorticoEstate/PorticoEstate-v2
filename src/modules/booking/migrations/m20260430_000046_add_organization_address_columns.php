<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add street, zip_code, city, and district columns to bb_organization';

	public function up(): void
	{
		$this->ensureColumn('bb_organization', 'street', ['type' => 'varchar', 'precision' => '255', 'nullable' => false, 'default' => '']);
		$this->ensureColumn('bb_organization', 'zip_code', ['type' => 'varchar', 'precision' => '255', 'nullable' => false, 'default' => '']);
		$this->ensureColumn('bb_organization', 'city', ['type' => 'varchar', 'precision' => '255', 'nullable' => false, 'default' => '']);
		$this->ensureColumn('bb_organization', 'district', ['type' => 'varchar', 'precision' => '255', 'nullable' => false, 'default' => '']);
	}
};
