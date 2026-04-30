<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add participant_limit column to bb_event';

	public function up(): void
	{
		$this->ensureColumn('bb_event', 'participant_limit', [
			'type' => 'int', 'precision' => 4, 'nullable' => true,
		]);
	}
};
