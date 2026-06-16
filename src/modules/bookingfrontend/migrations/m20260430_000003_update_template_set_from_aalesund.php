<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Migrate aalesund template_set to bookingfrontend';

	public function up(): void
	{
		// Only update rows that still have the old template_set value
		if ($this->tableExists('phpgw_preferences')) {
			$this->sql(
				"UPDATE phpgw_preferences SET preference_json = jsonb_set(preference_json, '{template_set}', '\"bookingfrontend\"', true) "
				. "WHERE preference_json->>'template_set' = 'aalesund'"
			);
		}
	}
};
