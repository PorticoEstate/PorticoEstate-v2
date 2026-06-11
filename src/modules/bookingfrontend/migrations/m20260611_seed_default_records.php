<?php

use App\modules\phpgwapi\services\Migration\Migration;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_user;

/**
 * Seed migration porting the default data from setup/default_records.inc.php.
 *
 * Fresh installs skip default_records.inc.php for migration-based modules,
 * so the anonymous guest account and config defaults are seeded here.
 * Existing installs already have the account — every step is guarded and
 * becomes a no-op.
 */
return new class extends Migration
{
	public string $description = 'Seed default records: bookingguest anonymous account and config defaults';

	public function up(): void
	{
		$this->seedGuestAccount();
		$this->seedConfigDefaults();
	}

	private function seedGuestAccount(): void
	{
		$accounts_obj = new Accounts();

		if ($accounts_obj->exists('bookingguest'))
		{
			return;
		}

		$passwd = bin2hex(random_bytes(6)) . 'ABab1!';

		$account = new phpgwapi_user();
		$account->lid = 'bookingguest';
		$account->firstname = 'booking';
		$account->lastname = 'Guest';
		$account->passwd = $passwd;
		$account->enabled = true;
		$account->expires = -1;
		$bookingguest = $accounts_obj->create($account, [], [], ['bookingfrontend']);

		$preferences = \createObject('phpgwapi.preferences');
		$preferences->set_account_id($bookingguest);
		$preferences->add('common', 'template_set', 'bookingfrontend');
		$preferences->save_repository(true, 'user');

		$config = \CreateObject('phpgwapi.config', 'bookingfrontend');
		$config->read();
		$config->value('anonymous_user', 'bookingguest');
		$config->value('anonymous_passwd', $passwd);
		$config->save_repository();
	}

	private function seedConfigDefaults(): void
	{
		$this->db->query(
			"SELECT COUNT(*) AS cnt FROM phpgw_config "
			. "WHERE config_app = 'bookingfrontend' AND config_name = 'usecookies'",
			__LINE__,
			__FILE__
		);
		$this->db->next_record();
		if ((int) $this->db->Record['cnt'] === 0)
		{
			$this->sql(
				"INSERT INTO phpgw_config (config_app, config_name, config_value) "
				. "VALUES ('bookingfrontend', 'usecookies', 'True')"
			);
		}
	}
};
