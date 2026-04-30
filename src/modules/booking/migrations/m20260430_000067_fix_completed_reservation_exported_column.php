<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Recreate exported column as nullable int with foreign key on bb_completed_reservation';

	public function up(): void
	{
		// Drop and recreate the exported column as nullable
		if ($this->columnExists('bb_completed_reservation', 'exported') && !$this->isNullable('bb_completed_reservation', 'exported')) {
			if ($this->constraintExists('bb_completed_reservation', 'bb_completed_reservation_exported_fkey')) {
				$this->sql("ALTER TABLE bb_completed_reservation DROP CONSTRAINT bb_completed_reservation_exported_fkey");
			}
			$this->sql("ALTER TABLE bb_completed_reservation ALTER COLUMN exported DROP NOT NULL");
			$this->sql("ALTER TABLE bb_completed_reservation ALTER COLUMN exported DROP DEFAULT");
		}

		if ($this->columnExists('bb_completed_reservation', 'exported') && !$this->constraintExists('bb_completed_reservation', 'bb_completed_reservation_exported_fkey')) {
			$this->sql("ALTER TABLE bb_completed_reservation ADD CONSTRAINT bb_completed_reservation_exported_fkey FOREIGN KEY (exported) REFERENCES bb_completed_reservation_export(id)");
		}
	}
};
