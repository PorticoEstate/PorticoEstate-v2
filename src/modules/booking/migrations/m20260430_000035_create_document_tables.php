<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_document_building and bb_document_resource tables';

	public function up(): void
	{
		foreach (['building', 'resource'] as $owner) {
			$this->createTable("bb_document_{$owner}", [
				'fd' => [
					'id' => ['type' => 'auto', 'nullable' => false],
					'name' => ['type' => 'varchar', 'precision' => '255', 'nullable' => false],
					'owner_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
					'category' => ['type' => 'varchar', 'precision' => '150', 'nullable' => false],
					'description' => ['type' => 'text', 'nullable' => true],
				],
				'pk' => ['id'],
				'fk' => [
					"bb_{$owner}" => ['owner_id' => 'id'],
				],
				'ix' => [],
				'uc' => [],
			]);
		}
	}
};
