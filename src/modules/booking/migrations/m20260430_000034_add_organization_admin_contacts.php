<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add admin_primary and admin_secondary columns with foreign keys to bb_organization';

	public function up(): void
	{
		$this->ensureColumn('bb_organization', 'admin_primary', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_organization', 'admin_secondary', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);

		if (!$this->constraintExists('bb_organization', 'bb_contact_person_primary_fkey')) {
			$this->sql("ALTER TABLE bb_organization ADD CONSTRAINT bb_contact_person_primary_fkey FOREIGN KEY (admin_primary) REFERENCES bb_contact_person(id)");
		}
		if (!$this->constraintExists('bb_organization', 'bb_contact_person_secondary_fkey')) {
			$this->sql("ALTER TABLE bb_organization ADD CONSTRAINT bb_contact_person_secondary_fkey FOREIGN KEY (admin_secondary) REFERENCES bb_contact_person(id)");
		}
	}
};
