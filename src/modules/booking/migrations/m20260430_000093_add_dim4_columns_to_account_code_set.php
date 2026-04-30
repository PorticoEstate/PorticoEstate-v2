<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add dim_4, dim_value_4, and dim_value_5 columns to bb_account_code_set';

	public function up(): void
	{
		$this->ensureColumn('bb_account_code_set', 'dim_4', [
			'type' => 'varchar',
			'precision' => '8',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_account_code_set', 'dim_value_4', [
			'type' => 'varchar',
			'precision' => '12',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_account_code_set', 'dim_value_5', [
			'type' => 'varchar',
			'precision' => '12',
			'nullable' => true,
		]);
	}
};
