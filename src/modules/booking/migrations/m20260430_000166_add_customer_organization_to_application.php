<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add customer_organization_name and customer_organization_id to bb_application';

	public function up(): void
	{
		$this->ensureColumn('bb_application', 'customer_organization_name', [
			'type' => 'varchar', 'precision' => '150', 'nullable' => true,
		]);
		$this->ensureColumn('bb_application', 'customer_organization_id', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
	}
};
