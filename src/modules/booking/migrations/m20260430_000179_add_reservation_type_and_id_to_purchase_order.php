<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add reservation_type and reservation_id columns to bb_purchase_order';

	public function up(): void
	{
		$this->ensureColumn('bb_purchase_order', 'reservation_type', [
			'type' => 'varchar', 'precision' => 70, 'nullable' => true,
		]);
		$this->ensureColumn('bb_purchase_order', 'reservation_id', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
	}
};
