<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add description column to bb_group';

	public function up(): void
	{
		$this->ensureColumn('bb_group', 'description', [
			'type' => 'varchar',
			'precision' => '250',
			'nullable' => false,
			'default' => '',
		]);
	}
};
