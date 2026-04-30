<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add officer_id column to bb_season with foreign key to phpgw_accounts';

	public function up(): void
	{
		$this->ensureColumn('bb_season', 'officer_id', ['type' => 'int', 'precision' => '4', 'nullable' => true]);

		if ($this->columnExists('bb_season', 'officer_id') && !$this->constraintExists('bb_season', 'bb_season_officer_id_fkey')) {
			$this->sql("ALTER TABLE bb_season ADD CONSTRAINT bb_season_officer_id_fkey FOREIGN KEY (officer_id) REFERENCES phpgw_accounts(account_id)");
		}

		if ($this->columnExists('bb_season', 'officer_id') && $this->isNullable('bb_season', 'officer_id')) {
			$this->sql("UPDATE bb_season SET officer_id=(SELECT account_id FROM phpgw_accounts WHERE account_lid='admin' LIMIT 1) WHERE officer_id IS NULL");
			$this->sql("ALTER TABLE bb_season ALTER COLUMN officer_id SET NOT NULL");
		}
	}
};
