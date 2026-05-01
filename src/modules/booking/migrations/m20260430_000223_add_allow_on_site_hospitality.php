<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add allow_on_site_hospitality to bb_hospitality';

	public function up(): void
	{
		$this->ensureColumn('bb_hospitality', 'allow_on_site_hospitality', [
			'type' => 'int',
			'precision' => 2,
			'nullable' => false,
			'default' => 0,
		]);
	}
};
