<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add dim_6, dim_7, dim_value_2/3/6/7 columns to bb_account_code_set';

	public function up(): void
	{
		$this->ensureColumn('bb_account_code_set', 'dim_6', [
			'type' => 'varchar', 'precision' => '8', 'nullable' => true,
		]);
		$this->ensureColumn('bb_account_code_set', 'dim_7', [
			'type' => 'varchar', 'precision' => '8', 'nullable' => true,
		]);
		$this->ensureColumn('bb_account_code_set', 'dim_value_2', [
			'type' => 'varchar', 'precision' => '12', 'nullable' => true,
		]);
		$this->ensureColumn('bb_account_code_set', 'dim_value_3', [
			'type' => 'varchar', 'precision' => '12', 'nullable' => true,
		]);
		$this->ensureColumn('bb_account_code_set', 'dim_value_6', [
			'type' => 'varchar', 'precision' => '12', 'nullable' => true,
		]);
		$this->ensureColumn('bb_account_code_set', 'dim_value_7', [
			'type' => 'varchar', 'precision' => '12', 'nullable' => true,
		]);
	}
};
