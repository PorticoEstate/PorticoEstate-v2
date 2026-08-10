<?php

namespace App\modules\todo\helpers;

class RedirectHelper
{
	public function process()
	{
		\phpgw::redirect_link('/todo/view/todos');
	}
}
