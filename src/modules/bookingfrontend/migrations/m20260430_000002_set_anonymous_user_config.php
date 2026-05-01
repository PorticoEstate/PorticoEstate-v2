<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Set anonymous_user config for bookingfrontend';

	public function up(): void
	{
		// Check if config already exists
		$this->db->query(
			"SELECT COUNT(*) AS cnt FROM phpgw_config "
			. "WHERE config_app = 'bookingfrontend' AND config_name = 'anonymous_user'",
			__LINE__,
			__FILE__
		);
		$this->db->next_record();
		if ((int) $this->db->Record['cnt'] === 0) {
			$this->sql(
				"INSERT INTO phpgw_config (config_app, config_name, config_value) "
				. "VALUES ('bookingfrontend', 'anonymous_user', 'bookingguest')"
			);
		}
	}
};
