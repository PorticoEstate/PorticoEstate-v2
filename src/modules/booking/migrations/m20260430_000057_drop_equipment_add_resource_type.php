<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop bb_equipment table and add type column to bb_resource';

	public function up(): void
	{
		$this->dropTable('bb_equipment');

		$this->ensureColumn('bb_resource', 'type', ['type' => 'varchar', 'precision' => '50', 'nullable' => true]);

		if ($this->columnExists('bb_resource', 'type') && $this->isNullable('bb_resource', 'type')) {
			$this->sql("UPDATE bb_resource SET type = 'Location' WHERE type IS NULL");
			$this->sql("ALTER TABLE bb_resource ALTER COLUMN type SET NOT NULL");
		}
	}
};
