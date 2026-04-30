<?php

use App\modules\phpgwapi\services\Migration\Migration;

/**
 * Baseline migration for the booking module.
 *
 * Creates all tables defined in tables_current.inc.php.
 * Each table creation is idempotent — existing tables are skipped.
 */
return new class extends Migration
{
	public string $description = 'Create baseline booking tables';

	public function up(): void
	{
		$phpgw_baseline = [];
		require SRC_ROOT_PATH . '/modules/booking/setup/tables_current.inc.php';

		foreach ($phpgw_baseline as $tableName => $tableDef) {
			$this->createTable($tableName, $tableDef);
		}
	}
};
