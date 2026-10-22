<?php

class uiindex
{
	var $public_functions = array('index' => true);

	public function index(): void
	{
		$query = array_intersect_key($_GET, array_flip([
			'fldball', 'folder', 'acctnum', 'sort', 'order', 'start', 'click_history'
		]));
		phpgw::redirect_link('/email/view/inbox', $query);
	}
}