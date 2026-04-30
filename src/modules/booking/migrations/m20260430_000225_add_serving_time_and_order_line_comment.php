<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add serving_time_iso to hospitality_order and comment to order_line';

	public function up(): void
	{
		$this->ensureColumn('bb_hospitality_order', 'serving_time_iso', [
			'type' => 'timestamp',
			'nullable' => true,
		]);

		$this->ensureColumn('bb_hospitality_order_line', 'comment', [
			'type' => 'text',
			'nullable' => true,
		]);
	}
};
