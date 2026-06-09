<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add sort column to bb_targetaudience and bb_agegroup';

	public function up(): void
	{
		$this->ensureColumn('bb_targetaudience', 'sort', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 0,
		]);
		$this->ensureColumn('bb_agegroup', 'sort', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 0,
		]);
	}
};
