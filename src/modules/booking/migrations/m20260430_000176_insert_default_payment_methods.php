<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Ensure bb_payment_method table exists and insert default Vipps and Etterfakturering payment methods';

	public function up(): void
	{
		// Ensure table exists (was a fix for out-of-sync databases in the original code)
		if (!$this->columnExists('bb_payment_method', 'payment_gateway_name'))
		{
			$this->createTable('bb_payment_method', [
				'fd' => [
					'id' => ['type' => 'auto', 'nullable' => false],
					'payment_gateway_name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
					'payment_gateway_mode' => ['type' => 'varchar', 'precision' => '6', 'nullable' => false],
					'is_default' => ['type' => 'int', 'precision' => '2', 'nullable' => true],
					'expires' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
					'created' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
					'changed' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
				],
				'pk' => ['id'],
				'fk' => [],
				'ix' => [],
				'uc' => [],
			]);
		}

		$this->sql(
			"INSERT INTO bb_payment_method (id, payment_gateway_name, payment_gateway_mode)"
			. " SELECT 1, 'Vipps', 'live'"
			. " WHERE NOT EXISTS (SELECT 1 FROM bb_payment_method WHERE id = 1)"
		);
		$this->sql(
			"INSERT INTO bb_payment_method (id, payment_gateway_name, payment_gateway_mode, is_default)"
			. " SELECT 2, 'Etterfakturering', 'live', 1"
			. " WHERE NOT EXISTS (SELECT 1 FROM bb_payment_method WHERE id = 2)"
		);
	}
};
