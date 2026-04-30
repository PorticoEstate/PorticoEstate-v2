<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop bb_bookingrelations, remove resources/category columns from bb_booking, add group FK, create bb_booking_resource';

	public function up(): void
	{
		$this->dropTable('bb_bookingrelations');
		$this->dropColumn('bb_booking', 'resources');
		$this->dropColumn('bb_booking', 'category');

		if (!$this->constraintExists('bb_booking', 'bb_booking_group_id_fkey')) {
			$this->sql("ALTER TABLE bb_booking ADD CONSTRAINT bb_booking_group_id_fkey FOREIGN KEY (group_id) REFERENCES bb_group(id)");
		}

		$this->createTable('bb_booking_resource', [
			'fd' => [
				'booking_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_booking' => ['booking_id' => 'id'],
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
