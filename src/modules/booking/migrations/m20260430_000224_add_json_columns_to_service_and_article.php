<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add description_json/name_json to bb_service, convert hospitality_article description to jsonb';

	public function up(): void
	{
		$this->ensureColumn('bb_service', 'description_json', [
			'type' => 'jsonb',
			'nullable' => true,
		]);

		$this->ensureColumn('bb_service', 'name_json', [
			'type' => 'jsonb',
			'nullable' => true,
		]);

		// Populate name_json from existing name column
		if ($this->columnExists('bb_service', 'name') && $this->columnExists('bb_service', 'name_json')) {
			$this->sql(
				"UPDATE bb_service SET name_json = jsonb_build_object('no', name) "
				. "WHERE name IS NOT NULL AND name != '' AND name_json IS NULL"
			);
		}

		// Convert bb_hospitality_article.description from text to jsonb
		if ($this->columnExists('bb_hospitality_article', 'description')) {
			$currentType = $this->getColumnType('bb_hospitality_article', 'description');
			if ($currentType === 'text') {
				$this->sql(
					"ALTER TABLE bb_hospitality_article ALTER COLUMN description TYPE JSONB "
					. "USING CASE WHEN description IS NOT NULL AND description != '' "
					. "THEN jsonb_build_object('no', description) ELSE NULL END"
				);
			}
		}
	}
};
