<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Change application_comment time to timestamp and add secret/owner_id to bb_application';

	public function up(): void
	{
		if ($this->columnExists('bb_application_comment', 'time') && $this->getColumnType('bb_application_comment', 'time') !== 'timestamp') {
			$this->sql("ALTER TABLE bb_application_comment ALTER COLUMN time TYPE timestamp USING time::timestamp");
		}

		$this->ensureColumn('bb_application', 'secret', ['type' => 'text', 'nullable' => false, 'default' => '']);
		$this->ensureColumn('bb_application', 'owner_id', ['type' => 'int', 'precision' => '4', 'nullable' => true]);

		if ($this->columnExists('bb_application', 'owner_id') && !$this->constraintExists('bb_application', 'bb_application_owner_id_fkey')) {
			$this->sql("ALTER TABLE bb_application ADD CONSTRAINT bb_application_owner_id_fkey FOREIGN KEY (owner_id) REFERENCES phpgw_accounts(account_id)");
		}
	}
};
