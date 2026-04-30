<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop date column and change from_/to_ to timestamp on bb_booking';

	public function up(): void
	{
		$this->dropColumn('bb_booking', 'date');

		if ($this->columnExists('bb_booking', 'from_') && $this->getColumnType('bb_booking', 'from_') !== 'timestamp') {
			$this->sql("ALTER TABLE bb_booking ALTER from_ TYPE timestamp USING NULL");
		}

		if ($this->columnExists('bb_booking', 'to_') && $this->getColumnType('bb_booking', 'to_') !== 'timestamp') {
			$this->sql("ALTER TABLE bb_booking ALTER to_ TYPE timestamp USING NULL");
		}
	}
};
