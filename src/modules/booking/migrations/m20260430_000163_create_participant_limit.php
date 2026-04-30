<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_participant_limit table for resource participant limits';

	public function up(): void
	{
		$this->createTable('bb_participant_limit', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'timestamp', 'nullable' => false],
				'quantity' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'modified_on' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'modified_by' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => ['bb_resource' => ['resource_id' => 'id']],
			'ix' => [],
			'uc' => [],
		]);
	}
};
