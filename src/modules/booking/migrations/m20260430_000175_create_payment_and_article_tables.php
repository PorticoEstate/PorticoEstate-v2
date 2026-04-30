<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_customer, bb_purchase_order, bb_article_category, bb_article_mapping, bb_purchase_order_line, bb_service, bb_resource_service, bb_article_price, bb_article_price_reduction, bb_payment_method, bb_payment tables and bb_article_view';

	public function up(): void
	{
		// Payment config sections (Vipps) are legacy admin config, not migrated here.
		// Locations::add('.article') is legacy location registration, not migrated here.

		$this->createTable('bb_customer', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'status' => ['type' => 'int', 'nullable' => false, 'precision' => '4', 'default' => 1],
				'customer_type' => ['type' => 'varchar', 'precision' => '12', 'nullable' => false, 'default' => 'person'],
				'customer_id' => ['type' => 'int', 'precision' => '4', 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_purchase_order', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'parent_id' => ['type' => 'int', 'nullable' => true, 'precision' => '4'],
				'status' => ['type' => 'int', 'nullable' => false, 'precision' => '4', 'default' => 1],
				'application_id' => ['type' => 'int', 'precision' => '4', 'nullable' => true],
				'customer_id' => ['type' => 'int', 'precision' => '4', 'nullable' => true],
				'timestamp' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'cancelled' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_application' => ['application_id' => 'id'],
				'bb_customer' => ['customer_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_article_category', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => '12', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);

		// Insert default article categories
		$this->sql(
			"INSERT INTO bb_article_category (id, name)"
			. " SELECT 1, 'resource'"
			. " WHERE NOT EXISTS (SELECT 1 FROM bb_article_category WHERE id = 1)"
		);
		$this->sql(
			"INSERT INTO bb_article_category (id, name)"
			. " SELECT 2, 'service'"
			. " WHERE NOT EXISTS (SELECT 1 FROM bb_article_category WHERE id = 2)"
		);

		$this->createTable('bb_article_mapping', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'article_cat_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'article_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'building_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'article_code' => ['type' => 'varchar', 'precision' => '100', 'nullable' => false],
				'unit' => ['type' => 'varchar', 'precision' => '12', 'nullable' => false],
				'tax_code' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'owner_id' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_article_category' => ['article_cat_id' => 'id'],
				'fm_ecomva' => ['tax_code' => 'id'],
			],
			'ix' => [],
			'uc' => [['article_cat_id', 'article_id']],
		]);

		$this->createTable('bb_purchase_order_line', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'order_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'status' => ['type' => 'int', 'nullable' => false, 'precision' => '4', 'default' => 1],
				'article_mapping_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'unit_price' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
				'overridden_unit_price' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
				'currency' => ['type' => 'varchar', 'precision' => '6', 'nullable' => false],
				'quantity' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
				'amount' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
				'tax_code' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'tax' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_purchase_order' => ['order_id' => 'id'],
				'bb_article_mapping' => ['article_mapping_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_service', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => '100', 'nullable' => false],
				'active' => ['type' => 'int', 'precision' => 4, 'nullable' => true, 'default' => '1'],
				'owner_id' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'description' => ['type' => 'text', 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_resource_service', [
			'fd' => [
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'service_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['resource_id', 'service_id'],
			'fk' => [
				'bb_resource' => ['resource_id' => 'id'],
				'bb_service' => ['service_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_article_price', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'article_mapping_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'price' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
				'remark' => ['type' => 'varchar', 'precision' => 100, 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_article_mapping' => ['article_mapping_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_article_price_reduction', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'article_mapping_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'percent' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_article_mapping' => ['article_mapping_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		// Create article view
		$this->sql(
			"CREATE OR REPLACE VIEW bb_article_view AS "
			. "SELECT id, name, description, active, 1 AS article_cat_id FROM bb_resource "
			. "UNION "
			. "SELECT id, name, description, active, 2 AS article_cat_id FROM bb_service"
		);

		$this->createTable('bb_payment_method', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'payment_gateway_name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'payment_gateway_mode' => ['type' => 'varchar', 'precision' => '6', 'nullable' => false],
				'is_default' => ['type' => 'int', 'precision' => '2', 'nullable' => true],
				'expires' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
				'created' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
				'changed' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_payment', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'order_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'payment_method_id' => ['type' => 'int', 'precision' => '4', 'nullable' => true],
				'payment_gateway_mode' => ['type' => 'varchar', 'precision' => '6', 'nullable' => false],
				'remote_id' => ['type' => 'varchar', 'precision' => 255, 'nullable' => true],
				'remote_state' => ['type' => 'varchar', 'precision' => 20, 'nullable' => true],
				'amount' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
				'currency' => ['type' => 'varchar', 'precision' => '6', 'nullable' => false],
				'refunded_amount' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
				'refunded_currency' => ['type' => 'varchar', 'precision' => '6', 'nullable' => false],
				'status' => ['type' => 'varchar', 'precision' => '20', 'nullable' => true],
				'created' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
				'autorized' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
				'expires' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
				'completet' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
				'captured' => ['type' => 'int', 'precision' => '8', 'nullable' => true],
				'avs_response_code' => ['type' => 'varchar', 'precision' => '15', 'nullable' => true],
				'avs_response_code_label' => ['type' => 'varchar', 'precision' => '35', 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_purchase_order' => ['order_id' => 'id'],
				'bb_payment_method' => ['payment_method_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
