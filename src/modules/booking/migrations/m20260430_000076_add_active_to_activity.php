<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add active column to bb_activity';

	public function up(): void
	{
		$this->ensureColumn('bb_activity', 'active', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 1,
		]);
	}
};
