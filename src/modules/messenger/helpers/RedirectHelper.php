<?php

namespace App\modules\messenger\helpers;

class RedirectHelper
{
	public function process()
	{
		\phpgw::redirect_link('/messenger/view/inbox');
	}
}
