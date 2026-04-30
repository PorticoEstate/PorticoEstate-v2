<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Rename bb_participant_log table to bb_participant';

	public function up(): void
	{
		$this->renameTable('bb_participant_log', 'bb_participant');
	}
};
