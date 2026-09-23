<?php

namespace App\modules\notes\helpers;

class RedirectHelper
{
	public function process()
	{
		\phpgw::redirect_link('/notes/view/notes');
	}
}
