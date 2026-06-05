<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add sort column to bb_resource';

	public function up(): void
	{
		$this->ensureColumn('bb_resource', 'sort', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);

		// Set default value for existing rows (idempotent — WHERE IS NULL)
		if ($this->tableExists('bb_resource') && $this->columnExists('bb_resource', 'sort')) {
			$this->sql("UPDATE bb_resource SET sort = 0 WHERE sort IS NULL");
		}
	}
};
