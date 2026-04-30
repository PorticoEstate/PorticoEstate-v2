<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add gi_arkiv (Geointegrasjon) archive configuration sections (legacy admin config)';

	public function up(): void
	{
		// This migration originally added gi_arkiv config sections and attributes
		// to phpgw_config2_* tables via the legacy admin.soconfig API.
		// These are runtime configuration entries, not schema changes.
		// The legacy setup code used CreateObject('admin.soconfig') which is not
		// available in the migration context. This config should be set up
		// through the admin interface if needed.
	}
};
