<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add simple_booking to bb_resource and set default values for booking columns';

	public function up(): void
	{
		$this->ensureColumn('bb_resource', 'simple_booking', [
			'type' => 'int', 'precision' => 2, 'nullable' => true,
		]);

		// Alter columns to add default value of -1
		if ($this->columnExists('bb_resource', 'booking_day_default_lenght'))
		{
			$this->sql("ALTER TABLE bb_resource ALTER COLUMN booking_day_default_lenght SET DEFAULT -1");
		}
		if ($this->columnExists('bb_resource', 'booking_dow_default_start'))
		{
			$this->sql("ALTER TABLE bb_resource ALTER COLUMN booking_dow_default_start SET DEFAULT -1");
		}
		if ($this->columnExists('bb_resource', 'booking_dow_default_end'))
		{
			$this->sql("ALTER TABLE bb_resource ALTER COLUMN booking_dow_default_end SET DEFAULT -1");
		}
		if ($this->columnExists('bb_resource', 'booking_time_default_start'))
		{
			$this->sql("ALTER TABLE bb_resource ALTER COLUMN booking_time_default_start SET DEFAULT -1");
		}
		if ($this->columnExists('bb_resource', 'booking_time_default_end'))
		{
			$this->sql("ALTER TABLE bb_resource ALTER COLUMN booking_time_default_end SET DEFAULT -1");
		}

		// Set null values to -1
		$this->sql(
			"UPDATE bb_resource SET"
			. " booking_day_default_lenght = -1,"
			. " booking_dow_default_start = -1,"
			. " booking_dow_default_end = -1,"
			. " booking_time_default_start = -1,"
			. " booking_time_default_end = -1"
			. " WHERE booking_time_default_start IS NULL"
		);
	}
};
