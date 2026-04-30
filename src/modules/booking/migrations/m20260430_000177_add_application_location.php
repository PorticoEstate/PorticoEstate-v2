<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Register .application location for booking module (legacy Locations setup)';

	public function up(): void
	{
		// The original code called Locations::add('.application', 'Application', 'booking')
		// This is a legacy location registration that is not a schema change.
		// If needed, it should be handled through the admin interface or application bootstrap.
	}
};
