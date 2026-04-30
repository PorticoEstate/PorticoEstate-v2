<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_hospitality_order_changelog table';

	public function up(): void
	{
		if (!$this->tableExists('bb_hospitality_order_changelog')) {
			$this->sql("
				CREATE TABLE bb_hospitality_order_changelog (
					id SERIAL PRIMARY KEY,
					order_id INTEGER NOT NULL REFERENCES bb_hospitality_order(id),
					case_officer_id INTEGER NULL,
					booking_user_id INTEGER NULL,
					changed_at TIMESTAMP NOT NULL DEFAULT NOW(),
					change_type VARCHAR(50) NOT NULL,
					old_value JSONB NULL,
					new_value JSONB NULL,
					comment TEXT NOT NULL,
					CONSTRAINT chk_changelog_user CHECK (case_officer_id IS NOT NULL OR booking_user_id IS NOT NULL)
				)
			");

			if (!$this->indexExists('bb_hospitality_order_changelog', 'idx_bb_hosp_order_changelog_order_id')) {
				$this->sql("CREATE INDEX idx_bb_hosp_order_changelog_order_id ON bb_hospitality_order_changelog(order_id)");
			}
		}
	}
};
