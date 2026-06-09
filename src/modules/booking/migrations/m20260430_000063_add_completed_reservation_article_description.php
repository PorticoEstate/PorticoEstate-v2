<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add article_description column to bb_completed_reservation';

	public function up(): void
	{
		$this->ensureColumn('bb_completed_reservation', 'article_description', ['type' => 'varchar', 'precision' => '35', 'nullable' => false, 'default' => '']);
	}
};
