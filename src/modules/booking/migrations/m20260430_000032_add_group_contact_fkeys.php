<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add foreign key constraints for contact_primary and contact_secondary on bb_group';

	public function up(): void
	{
		if (!$this->constraintExists('bb_group', 'bb_contact_person_primary_fkey')) {
			$this->sql("ALTER TABLE bb_group ADD CONSTRAINT bb_contact_person_primary_fkey FOREIGN KEY (contact_primary) REFERENCES bb_contact_person(id)");
		}
		if (!$this->constraintExists('bb_group', 'bb_contact_person_secondary_fkey')) {
			$this->sql("ALTER TABLE bb_group ADD CONSTRAINT bb_contact_person_secondary_fkey FOREIGN KEY (contact_secondary) REFERENCES bb_contact_person(id)");
		}
	}
};
