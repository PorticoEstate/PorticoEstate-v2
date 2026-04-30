<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Change bb_contact_person ssn column type from int to varchar(12)';

	public function up(): void
	{
		if ($this->columnExists('bb_contact_person', 'ssn') && $this->getColumnType('bb_contact_person', 'ssn') !== 'varchar') {
			$this->sql("ALTER TABLE bb_contact_person ALTER COLUMN ssn TYPE varchar(12) USING NULL");
		}
	}
};
