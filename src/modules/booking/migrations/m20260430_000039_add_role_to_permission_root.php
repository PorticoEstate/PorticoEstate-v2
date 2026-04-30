<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add role column to bb_permission_root';

	public function up(): void
	{
		$this->ensureColumn('bb_permission_root', 'role', [
			'type' => 'varchar',
			'precision' => '255',
			'nullable' => false,
		]);
	}
};
