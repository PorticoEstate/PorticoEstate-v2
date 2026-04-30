<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Change bb_event description column type to text';

	public function up(): void
	{
		if ($this->columnExists('bb_event', 'description') && $this->getColumnType('bb_event', 'description') !== 'text') {
			$this->sql("ALTER TABLE bb_event ALTER COLUMN description TYPE text");
		}
	}
};
