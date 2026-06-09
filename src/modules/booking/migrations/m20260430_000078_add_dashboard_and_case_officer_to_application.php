<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add display_in_dashboard and case_officer_id columns to bb_application';

	public function up(): void
	{
		$this->ensureColumn('bb_application', 'display_in_dashboard', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 1,
		]);
		$this->ensureColumn('bb_application', 'case_officer_id', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);

		if (!$this->constraintExists('bb_application', 'bb_case_officer_id_fkey')) {
			$this->sql("ALTER TABLE bb_application ADD CONSTRAINT bb_case_officer_id_fkey FOREIGN KEY (case_officer_id) REFERENCES phpgw_accounts(account_id)");
		}
	}
};
