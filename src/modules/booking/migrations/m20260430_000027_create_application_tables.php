<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_application, bb_application_resource, bb_application_comment, and bb_application_date tables';

	public function up(): void
	{
		$this->createTable('bb_application', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'activity_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'description' => ['type' => 'text', 'nullable' => false],
				'contact_name' => ['type' => 'text', 'nullable' => false],
				'contact_email' => ['type' => 'text', 'nullable' => false],
				'contact_phone' => ['type' => 'text', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_activity' => ['activity_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_application_resource', [
			'fd' => [
				'application_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['application_id', 'resource_id'],
			'fk' => [
				'bb_application' => ['application_id' => 'id'],
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_application_comment', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'application_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'time' => ['type' => 'text', 'nullable' => false],
				'author' => ['type' => 'text', 'nullable' => false],
				'comment' => ['type' => 'text', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_application' => ['application_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_application_date', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'application_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'text', 'nullable' => false],
				'to_' => ['type' => 'text', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_application' => ['application_id' => 'id'],
			],
			'ix' => [],
			'uc' => [['application_id', 'from_', 'to_']],
		]);
	}
};
