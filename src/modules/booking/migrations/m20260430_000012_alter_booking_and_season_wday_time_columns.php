<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Change from_ and to_ columns to time type on bb_booking and bb_season_wday';

	public function up(): void
	{
		if ($this->columnExists('bb_booking', 'from_') && $this->getColumnType('bb_booking', 'from_') !== 'time') {
			$this->sql("ALTER TABLE bb_booking ALTER from_ TYPE time USING NULL");
		}

		if ($this->columnExists('bb_booking', 'to_') && $this->getColumnType('bb_booking', 'to_') !== 'time') {
			$this->sql("ALTER TABLE bb_booking ALTER to_ TYPE time USING NULL");
		}

		if ($this->columnExists('bb_season_wday', 'from_') && $this->getColumnType('bb_season_wday', 'from_') !== 'time') {
			$this->sql("ALTER TABLE bb_season_wday ALTER from_ TYPE time USING NULL");
		}

		if ($this->columnExists('bb_season_wday', 'to_') && $this->getColumnType('bb_season_wday', 'to_') !== 'time') {
			$this->sql("ALTER TABLE bb_season_wday ALTER to_ TYPE time USING NULL");
		}
	}
};
