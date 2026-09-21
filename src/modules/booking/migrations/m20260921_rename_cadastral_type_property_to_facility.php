<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Rename cadastral type PROPERTY to FACILITY';

	public function up(): void
	{
		$this->assertTableExists('bb_building_cadastral_reference');

		$this->sql('ALTER TABLE bb_building_cadastral_reference DROP CONSTRAINT IF EXISTS bb_building_cadastral_reference_type_check');
		$this->sql("UPDATE bb_building_cadastral_reference SET cadastral_type = 'FACILITY' WHERE cadastral_type = 'PROPERTY'");
		$this->sql("ALTER TABLE bb_building_cadastral_reference ADD CONSTRAINT bb_building_cadastral_reference_type_check CHECK (cadastral_type IN ('BUILDING', 'FACILITY'))");
	}
};
