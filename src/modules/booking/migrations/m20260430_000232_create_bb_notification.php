<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_notification table for real-time comment notifications';

	public function up(): void
	{
		// No FK dependencies — bb_notification is a standalone table.
		// createTable() already skips if the table exists (idempotent).

		$this->createTable('bb_notification', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'source_type' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'source_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'entity_type' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'entity_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'recipient_user_type' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'recipient_identifier' => ['type' => 'varchar', 'precision' => '64', 'nullable' => false],
				'title' => ['type' => 'varchar', 'precision' => '255', 'nullable' => false],
				'message' => ['type' => 'text', 'nullable' => true],
				'link' => ['type' => 'varchar', 'precision' => '512', 'nullable' => true],
				'is_read' => ['type' => 'bool', 'nullable' => false, 'default' => 'false'],
				'read_at' => ['type' => 'timestamp', 'nullable' => true],
				'data' => ['type' => 'jsonb', 'nullable' => true],
				'created' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'now()'],
				'expires_at' => ['type' => 'timestamp', 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [
				['recipient_user_type', 'recipient_identifier', 'is_read'],
				['entity_type', 'entity_id', 'is_read'],
			],
			'uc' => [],
		]);
	}
};
