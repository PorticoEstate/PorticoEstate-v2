<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add frontend_modified timestamp column to bb_application';

	public function up(): void
	{
		$this->ensureColumn('bb_application', 'frontend_modified', [
			'type' => 'timestamp',
			'nullable' => true,
		]);
	}
};
