<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add status/created/modified to bb_application, alter date columns, create target audience and age group tables';

	public function up(): void
	{
		$this->ensureColumn('bb_application', 'status', [
			'type' => 'text',
			'nullable' => false,
		]);
		$this->ensureColumn('bb_application', 'created', [
			'type' => 'timestamp',
			'nullable' => false,
			'default' => 'now',
		]);
		$this->ensureColumn('bb_application', 'modified', [
			'type' => 'timestamp',
			'nullable' => false,
			'default' => 'now',
		]);

		if ($this->columnExists('bb_application_date', 'from_') && $this->getColumnType('bb_application_date', 'from_') !== 'timestamp') {
			$this->sql("ALTER TABLE bb_application_date ALTER COLUMN from_ TYPE timestamp USING from_::timestamp");
		}
		if ($this->columnExists('bb_application_date', 'to_') && $this->getColumnType('bb_application_date', 'to_') !== 'timestamp') {
			$this->sql("ALTER TABLE bb_application_date ALTER COLUMN to_ TYPE timestamp USING to_::timestamp");
		}

		$this->createTable('bb_application_targetaudience', [
			'fd' => [
				'application_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'targetaudience_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['application_id', 'targetaudience_id'],
			'fk' => [
				'bb_application' => ['application_id' => 'id'],
				'bb_targetaudience' => ['targetaudience_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_application_agegroup', [
			'fd' => [
				'application_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'agegroup_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'male' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'female' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['application_id', 'agegroup_id'],
			'fk' => [
				'bb_application' => ['application_id' => 'id'],
				'bb_agegroup' => ['agegroup_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
