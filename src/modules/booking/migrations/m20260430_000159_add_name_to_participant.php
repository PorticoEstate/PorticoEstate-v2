<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add name column to bb_participant';

	public function up(): void
	{
		$this->ensureColumn('bb_participant', 'name', [
			'type' => 'varchar', 'precision' => 150, 'nullable' => true,
		]);
	}
};
