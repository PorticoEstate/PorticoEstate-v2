<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add description column to bb_completed_reservation';

	public function up(): void
	{
		$this->ensureColumn('bb_completed_reservation', 'description', ['type' => 'text', 'nullable' => false, 'default' => '']);
	}
};
