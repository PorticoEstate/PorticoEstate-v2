<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Change description columns to text type on building, organization, resource, and group';

	public function up(): void
	{
		if ($this->columnExists('bb_building', 'description') && $this->getColumnType('bb_building', 'description') !== 'text') {
			$this->sql("ALTER TABLE bb_building ALTER COLUMN description TYPE text");
		}

		if ($this->columnExists('bb_organization', 'description') && $this->getColumnType('bb_organization', 'description') !== 'text') {
			$this->sql("ALTER TABLE bb_organization ALTER COLUMN description TYPE text");
		}

		if ($this->columnExists('bb_resource', 'description') && $this->getColumnType('bb_resource', 'description') !== 'text') {
			$this->sql("ALTER TABLE bb_resource ALTER COLUMN description TYPE text");
		}

		if ($this->columnExists('bb_group', 'description') && $this->getColumnType('bb_group', 'description') !== 'text') {
			$this->sql("ALTER TABLE bb_group ALTER COLUMN description TYPE text");
		}
	}
};
