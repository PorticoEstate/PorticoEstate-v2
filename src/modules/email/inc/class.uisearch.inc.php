<?php

class uisearch
{
	var $public_functions = array('form' => true);

	public function form(): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		phpgw::redirect_link('/email/view/search', $query);
	}
}