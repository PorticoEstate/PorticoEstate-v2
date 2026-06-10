<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add in_tax_register column to bb_organization';

	public function up(): void
	{
		$this->ensureColumn('bb_organization', 'in_tax_register', [
			'type' => 'int', 'precision' => 2, 'nullable' => true, 'default' => 0,
		]);
	}
};
