<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add organization_number column to bb_organization';

	public function up(): void
	{
		$this->ensureColumn('bb_organization', 'organization_number', ['type' => 'varchar', 'precision' => '9', 'nullable' => false, 'default' => '']);
	}
};
