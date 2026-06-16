<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add sms_total column to bb_booking and bb_event';

	public function up(): void
	{
		$this->ensureColumn('bb_booking', 'sms_total', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_event', 'sms_total', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);
	}
};
