<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add quantity column to bb_participant_log';

	public function up(): void
	{
		$this->ensureColumn('bb_participant_log', 'quantity', [
			'type' => 'int', 'precision' => 4, 'default' => 1, 'nullable' => false,
		]);
	}
};
