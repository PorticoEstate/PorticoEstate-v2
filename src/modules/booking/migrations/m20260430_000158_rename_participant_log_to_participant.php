<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Rename bb_participant_log table to bb_participant';

	public function up(): void
	{
		if ($this->tableExists('bb_participant_log') && !$this->tableExists('bb_participant')) {
			$this->sql("ALTER TABLE bb_participant_log RENAME TO bb_participant");
			$this->sql("ALTER SEQUENCE IF EXISTS seq_bb_participant_log RENAME TO seq_bb_participant");
		}
	}
};
