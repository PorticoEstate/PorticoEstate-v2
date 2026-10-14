<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Widen bb_completed_reservation.article_description to varchar(100)';

	public function up(): void
	{
		if ($this->columnExists('bb_completed_reservation', 'article_description')) {
			$this->sql("ALTER TABLE bb_completed_reservation ALTER COLUMN article_description TYPE varchar(100)");
		}
	}
};
