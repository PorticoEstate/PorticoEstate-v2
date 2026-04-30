<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_event_targetaudience and bb_event_agegroup tables';

	public function up(): void
	{
		$this->createTable('bb_event_targetaudience', [
			'fd' => [
				'event_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'targetaudience_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['event_id', 'targetaudience_id'],
			'fk' => [
				'bb_event' => ['event_id' => 'id'],
				'bb_targetaudience' => ['targetaudience_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_event_agegroup', [
			'fd' => [
				'event_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'agegroup_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'male' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'female' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['event_id', 'agegroup_id'],
			'fk' => [
				'bb_event' => ['event_id' => 'id'],
				'bb_agegroup' => ['agegroup_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
