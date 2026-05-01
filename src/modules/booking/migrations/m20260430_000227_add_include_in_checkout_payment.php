<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add include_in_checkout_payment to bb_hospitality';

	public function up(): void
	{
		$this->ensureColumn('bb_hospitality', 'include_in_checkout_payment', [
			'type' => 'int',
			'precision' => 2,
			'nullable' => false,
			'default' => 0,
		]);
	}
};
