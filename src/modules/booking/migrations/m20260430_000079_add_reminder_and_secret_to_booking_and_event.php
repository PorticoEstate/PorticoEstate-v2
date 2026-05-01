<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add reminder and secret columns to bb_booking and bb_event';

	public function up(): void
	{
		$this->ensureColumn('bb_booking', 'reminder', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 1,
		]);
		$this->ensureColumn('bb_booking', 'secret', [
			'type' => 'text',
			'nullable' => true,
		]);

		// Populate secret for existing rows that have NULL secret
		$this->sql("UPDATE bb_booking SET secret = substring(md5(from_::text || id::text || group_id::text) from 0 for 11) WHERE secret IS NULL");

		if ($this->columnExists('bb_booking', 'secret') && $this->isNullable('bb_booking', 'secret')) {
			$this->sql("ALTER TABLE bb_booking ALTER COLUMN secret SET NOT NULL");
		}

		$this->ensureColumn('bb_event', 'reminder', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => false,
			'default' => 1,
		]);
		$this->ensureColumn('bb_event', 'secret', [
			'type' => 'text',
			'nullable' => true,
		]);

		// Populate secret for existing rows that have NULL secret
		$this->sql("UPDATE bb_event SET secret = substring(md5(from_::text || id::text || activity_id::text) from 0 for 11) WHERE secret IS NULL");

		if ($this->columnExists('bb_event', 'secret') && $this->isNullable('bb_event', 'secret')) {
			$this->sql("ALTER TABLE bb_event ALTER COLUMN secret SET NOT NULL");
		}
	}
};
