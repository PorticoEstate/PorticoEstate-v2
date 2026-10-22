<?php

class uifolder
{
	var $public_functions = array('folder' => true);

	public function folder(): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		phpgw::redirect_link('/email/view/folders', $query);
	}
}