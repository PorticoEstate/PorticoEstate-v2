<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add active columns to organization, group, resource, season, allocation, booking, and application tables';

	public function up(): void
	{
		$tables = [
			'bb_organization',
			'bb_group',
			'bb_resource',
			'bb_season',
			'bb_allocation',
			'bb_booking',
			'bb_application',
		];

		foreach ($tables as $table) {
			$this->ensureColumn($table, 'active', [
				'type' => 'int',
				'precision' => '4',
				'nullable' => false,
				'default' => 1,
			]);
		}
	}
};
