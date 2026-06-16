<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Ensure remaining aalesund template_set migrated to bookingfrontend';

	public function up(): void
	{
		// Same operation as previous migration - catches any rows missed or added since
		if ($this->tableExists('phpgw_preferences')) {
			$this->sql(
				"UPDATE phpgw_preferences SET preference_json = jsonb_set(preference_json, '{template_set}', '\"bookingfrontend\"', true) "
				. "WHERE preference_json->>'template_set' = 'aalesund'"
			);
		}
	}
};
