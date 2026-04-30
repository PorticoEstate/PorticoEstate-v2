<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add cost column to bb_allocation and allocation_id foreign key to bb_booking';

	public function up(): void
	{
		$this->ensureColumn('bb_allocation', 'cost', [
			'type' => 'decimal',
			'precision' => '10,2',
			'nullable' => false,
			'default' => '0',
		]);

		$this->ensureColumn('bb_booking', 'allocation_id', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);

		if (!$this->constraintExists('bb_booking', 'bb_booking_allocation_id_fkey')) {
			$this->sql("ALTER TABLE bb_booking ADD CONSTRAINT bb_booking_allocation_id_fkey FOREIGN KEY (allocation_id) REFERENCES bb_allocation(id)");
		}
	}
};
