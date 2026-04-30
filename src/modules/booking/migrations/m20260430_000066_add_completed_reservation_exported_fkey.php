<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add foreign key constraint on exported column of bb_completed_reservation';

	public function up(): void
	{
		if ($this->columnExists('bb_completed_reservation', 'exported') && !$this->constraintExists('bb_completed_reservation', 'bb_completed_reservation_exported_fkey')) {
			$this->sql("ALTER TABLE bb_completed_reservation ADD CONSTRAINT bb_completed_reservation_exported_fkey FOREIGN KEY (exported) REFERENCES bb_completed_reservation_export(id)");
		}
	}
};
