<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add activity_id column with foreign key to bb_organization and bb_group';

	public function up(): void
	{
		$this->ensureColumn('bb_organization', 'activity_id', ['type' => 'int', 'precision' => '4', 'nullable' => true]);

		if ($this->columnExists('bb_organization', 'activity_id') && !$this->constraintExists('bb_organization', 'bb_organization_activity_id_fkey')) {
			$this->sql("ALTER TABLE bb_organization ADD CONSTRAINT bb_organization_activity_id_fkey FOREIGN KEY (activity_id) REFERENCES bb_activity(id)");
		}

		$this->ensureColumn('bb_group', 'activity_id', ['type' => 'int', 'precision' => '4', 'nullable' => true]);

		if ($this->columnExists('bb_group', 'activity_id') && !$this->constraintExists('bb_group', 'bb_group_activity_id_fkey')) {
			$this->sql("ALTER TABLE bb_group ADD CONSTRAINT bb_group_activity_id_fkey FOREIGN KEY (activity_id) REFERENCES bb_activity(id)");
		}
	}
};
