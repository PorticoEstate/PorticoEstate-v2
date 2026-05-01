<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create hospitality tables for catering/service ordering';

	public function up(): void
	{
		$this->createTable('bb_hospitality', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => 255, 'nullable' => false],
				'description' => ['type' => 'text', 'nullable' => true],
				'active' => ['type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 1],
				'remote_serving_enabled' => ['type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 0],
				'order_by_time_value' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'order_by_time_unit' => ['type' => 'varchar', 'precision' => 10, 'nullable' => true],
				'created' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'modified' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'created_by' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'modified_by' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_hospitality_remote_location', [
			'fd' => [
				'hospitality_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'active' => ['type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 1],
			],
			'pk' => ['hospitality_id', 'resource_id'],
			'fk' => [
				'bb_hospitality' => ['hospitality_id' => 'id'],
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_hospitality_article_group', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'hospitality_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => 255, 'nullable' => false],
				'sort_order' => ['type' => 'int', 'precision' => 4, 'nullable' => false, 'default' => 0],
				'active' => ['type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 1],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_hospitality' => ['hospitality_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_hospitality_article', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'hospitality_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'article_group_id' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'article_mapping_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'description' => ['type' => 'text', 'nullable' => true],
				'sort_order' => ['type' => 'int', 'precision' => 4, 'nullable' => false, 'default' => 0],
				'active' => ['type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 1],
				'override_price' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true],
				'override_tax_code' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_hospitality' => ['hospitality_id' => 'id'],
				'bb_hospitality_article_group' => ['article_group_id' => 'id'],
				'bb_article_mapping' => ['article_mapping_id' => 'id'],
			],
			'ix' => [],
			'uc' => [['hospitality_id', 'article_mapping_id']],
		]);

		$this->createTable('bb_hospitality_order', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'application_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'hospitality_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'location_resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'status' => ['type' => 'varchar', 'precision' => 20, 'nullable' => false, 'default' => 'pending'],
				'comment' => ['type' => 'text', 'nullable' => true],
				'special_requirements' => ['type' => 'text', 'nullable' => true],
				'created' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'modified' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'created_by' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'modified_by' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_application' => ['application_id' => 'id'],
				'bb_hospitality' => ['hospitality_id' => 'id'],
				'bb_resource' => ['location_resource_id' => 'id'],
			],
			'ix' => [
				['application_id'],
				['hospitality_id'],
			],
			'uc' => [],
		]);

		$this->createTable('bb_hospitality_order_line', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'order_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'hospitality_article_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'quantity' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => false, 'default' => '1.0'],
				'unit_price' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => false],
				'tax_code' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'amount' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_hospitality_order' => ['order_id' => 'id'],
				'bb_hospitality_article' => ['hospitality_article_id' => 'id'],
			],
			'ix' => [
				['order_id'],
			],
			'uc' => [],
		]);

		$this->createTable('bb_hospitality_order_document', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => 255, 'nullable' => false],
				'owner_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'category' => ['type' => 'varchar', 'precision' => 150, 'nullable' => false],
				'description' => ['type' => 'text', 'nullable' => true],
				'metadata' => ['type' => 'jsonb', 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_hospitality_order' => ['owner_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
