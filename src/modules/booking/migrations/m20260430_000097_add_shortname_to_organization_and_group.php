<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add shortname column to bb_organization and bb_group';

	public function up(): void
	{
		$this->ensureColumn('bb_organization', 'shortname', [
			'type' => 'varchar',
			'precision' => '11',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_group', 'shortname', [
			'type' => 'varchar',
			'precision' => '11',
			'nullable' => true,
		]);
	}
};
