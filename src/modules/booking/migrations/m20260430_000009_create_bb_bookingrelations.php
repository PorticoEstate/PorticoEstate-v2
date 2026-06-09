<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_bookingrelations table';

	public function up(): void
	{
		$this->createTable('bb_bookingrelations', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'bb_booking_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'bb_resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_booking' => ['bb_booking_id' => 'id'],
				'bb_resource' => ['bb_resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
