<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add type column to bb_application_comment';

	public function up(): void
	{
		$this->ensureColumn('bb_application_comment', 'type', [
			'type' => 'text',
			'nullable' => false,
			'default' => 'comment',
		]);
	}
};
