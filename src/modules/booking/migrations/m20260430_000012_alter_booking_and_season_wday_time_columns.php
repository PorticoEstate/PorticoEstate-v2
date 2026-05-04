<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Change from_ and to_ columns to time type on bb_booking and bb_season_wday';

	public function up(): void
	{
		// Skip if columns are already time or a later type (timestamp) — means this migration's
		// change was already applied or superseded by a later migration.
		$skipTypes = ['time without time zone', 'timestamp without time zone'];

		if ($this->columnExists('bb_booking', 'from_') && !in_array($this->getColumnType('bb_booking', 'from_'), $skipTypes)) {
			$this->sql("DROP VIEW IF EXISTS bb_application_association");
			$this->sql("ALTER TABLE bb_booking ALTER from_ TYPE time USING NULL");
		}

		if ($this->columnExists('bb_booking', 'to_') && !in_array($this->getColumnType('bb_booking', 'to_'), $skipTypes)) {
			$this->sql("ALTER TABLE bb_booking ALTER to_ TYPE time USING NULL");
		}

		if ($this->columnExists('bb_season_wday', 'from_') && !in_array($this->getColumnType('bb_season_wday', 'from_'), $skipTypes)) {
			$this->sql("ALTER TABLE bb_season_wday ALTER from_ TYPE time USING NULL");
		}

		if ($this->columnExists('bb_season_wday', 'to_') && !in_array($this->getColumnType('bb_season_wday', 'to_'), $skipTypes)) {
			$this->sql("ALTER TABLE bb_season_wday ALTER to_ TYPE time USING NULL");
		}
	}
};
