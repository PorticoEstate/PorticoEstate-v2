<?php

namespace App\modules\emailadmin\helpers;

class RedirectHelper
{
	public function process()
	{
		\phpgw::redirect_link('/emailadmin/view/profiles');
	}
}