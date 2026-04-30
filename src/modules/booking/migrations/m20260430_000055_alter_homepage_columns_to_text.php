<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Change homepage columns to text type on organization, building, and contact_person';

	public function up(): void
	{
		if ($this->columnExists('bb_organization', 'homepage') && $this->getColumnType('bb_organization', 'homepage') !== 'text') {
			$this->sql("ALTER TABLE bb_organization ALTER COLUMN homepage TYPE text");
		}

		if ($this->columnExists('bb_building', 'homepage') && $this->getColumnType('bb_building', 'homepage') !== 'text') {
			$this->sql("ALTER TABLE bb_building ALTER COLUMN homepage TYPE text");
		}

		if ($this->columnExists('bb_contact_person', 'homepage') && $this->getColumnType('bb_contact_person', 'homepage') !== 'text') {
			$this->sql("ALTER TABLE bb_contact_person ALTER COLUMN homepage TYPE text");
		}
	}
};
