<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Change default value of reminder column to 0 on bb_booking and bb_event';

	public function up(): void
	{
		if ($this->columnExists('bb_booking', 'reminder')) {
			$this->sql("ALTER TABLE bb_booking ALTER COLUMN reminder SET DEFAULT 0");
		}
		if ($this->columnExists('bb_event', 'reminder')) {
			$this->sql("ALTER TABLE bb_event ALTER COLUMN reminder SET DEFAULT 0");
		}
	}
};
