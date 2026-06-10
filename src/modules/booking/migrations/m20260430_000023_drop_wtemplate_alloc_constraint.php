<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop unique constraint on bb_wtemplate_alloc season_id';

	public function up(): void
	{
		if ($this->constraintExists('bb_wtemplate_alloc', 'bb_wtemplate_alloc_season_id_key')) {
			$this->sql('ALTER TABLE bb_wtemplate_alloc DROP CONSTRAINT "bb_wtemplate_alloc_season_id_key"');
		}
	}
};
