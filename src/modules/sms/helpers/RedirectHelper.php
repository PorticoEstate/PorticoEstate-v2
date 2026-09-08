<?php

namespace App\modules\sms\helpers;

use App\modules\phpgwapi\services\Settings;

class RedirectHelper
{
	public function process()
	{
		$userSettings = Settings::getInstance()->get('user');

		$start_page = (string) ($userSettings['preferences']['sms']['default_start_page'] ?? '');

		// Inbox and outbox now have modern Slim/Twig pages; everything else
		// (autoreply/board/command/custom/poll) still uses the legacy menuaction UI.
		if ($start_page === '' || $start_page === 'sms.index')
		{
			\phpgw::redirect_link('/sms/view/inbox');
		}
		if ($start_page === 'uisms.outbox')
		{
			\phpgw::redirect_link('/sms/view/outbox');
		}

		\phpgw::redirect_link('/index.php', array('menuaction' => "sms.ui{$start_page}"));
	}
}
