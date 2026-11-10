<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_price_category and link prices and order lines to it (ref #1477). One global list of price categories (e.g. «Lag og foreninger»), so that an applicant booking several resources chooses the category once and every resource gets its own price in it. bb_article_price.category_id is optional: a price without one behaves as before. Where a price has one, the category name takes the place of the remark in what the applicant sees and in price_label. The name is unique ignoring case, stored without surrounding whitespace and is never only an amount, all enforced here rather than in the UI. The key from bb_article_price is ON DELETE RESTRICT: deleting a category is not supported, and SET NULL would silently change what applicants see. bb_purchase_order_line.category_id records the category a line was priced in, with no foreign key: price_label is the durable record, as for article_price_id (m20261005). No backfill: no price gets a category from its remark.';

	private const TABLE = 'bb_price_category';

	/**
	 * Whitespace a stored name may not start or end with: the ASCII class plus
	 * the Unicode spaces, which PostgreSQL's \s does not cover (no-break space
	 * among them). The server trims the same set before it inserts.
	 */
	private const SPACE_CLASS = '[\\s\\u00a0\\u1680\\u2000-\\u200a\\u2028\\u2029\\u202f\\u205f\\u3000\\ufeff]';

	public function up(): void
	{
		$this->assertTableExists('bb_article_price');
		$this->assertTableExists('bb_purchase_order_line');

		$this->createTable(self::TABLE, [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => 100, 'nullable' => false],
				'active' => ['type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 1],
				'sort' => ['type' => 'int', 'precision' => 4, 'nullable' => false, 'default' => 0],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);

		// Unique ignoring case; with the trim check below, also ignoring surrounding whitespace
		$this->addIndex(self::TABLE, 'bb_price_category_name_lower_uidx', ['lower(name)'], true);

		if (!$this->constraintExists(self::TABLE, 'bb_price_category_name_trimmed_check'))
		{
			$space = self::SPACE_CLASS;
			$this->sql("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT bb_price_category_name_trimmed_check"
				. " CHECK (name <> '' AND name !~ '^{$space}' AND name !~ '{$space}\$')");
		}

		// A name that is only an amount reads as the amount-only label, which is
		// hidden on minute-priced lines (isAmountOnlyLabel in the Next client)
		if (!$this->constraintExists(self::TABLE, 'bb_price_category_name_not_amount_check'))
		{
			$this->sql("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT bb_price_category_name_not_amount_check"
				. " CHECK (name !~ '^[0-9]+([.,][0-9]+)?\$')");
		}

		$this->ensureColumn('bb_article_price', 'category_id', [
			'type' => 'int',
			'precision' => 4,
			'nullable' => true,
		]);

		if (!$this->constraintExists('bb_article_price', 'bb_article_price_category_id_fkey'))
		{
			$this->sql("ALTER TABLE bb_article_price ADD CONSTRAINT bb_article_price_category_id_fkey"
				. " FOREIGN KEY (category_id) REFERENCES " . self::TABLE . " (id) ON DELETE RESTRICT");
		}

		$this->ensureColumn('bb_purchase_order_line', 'category_id', [
			'type' => 'int',
			'precision' => 4,
			'nullable' => true,
		]);
	}
};
