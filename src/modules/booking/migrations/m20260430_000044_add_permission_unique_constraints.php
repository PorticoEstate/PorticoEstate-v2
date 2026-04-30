<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add unique constraints to bb_permission and bb_permission_root';

	public function up(): void
	{
		if (!$this->constraintExists('bb_permission', 'bb_permission_subject_id_key')) {
			$this->sql("ALTER TABLE bb_permission ADD CONSTRAINT bb_permission_subject_id_key UNIQUE (subject_id, role, object_type, object_id)");
		}

		if (!$this->constraintExists('bb_permission_root', 'bb_permission_root_subject_id_key')) {
			$this->sql("ALTER TABLE bb_permission_root ADD CONSTRAINT bb_permission_root_subject_id_key UNIQUE (subject_id, role)");
		}
	}
};
