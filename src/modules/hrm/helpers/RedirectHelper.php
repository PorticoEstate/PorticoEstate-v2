<?php

namespace App\modules\hrm\helpers;

class RedirectHelper
{
	public function process()
	{
		\phpgw::redirect_link('/hrm/view/users');
	}
}
