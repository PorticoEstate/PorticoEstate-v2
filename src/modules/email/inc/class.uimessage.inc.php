<?php

class uimessage
{
	var $public_functions = array('message' => true, 'printable' => true);

	public function message(): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		phpgw::redirect_link('/email/view/message', $query);
	}

	public function printable(): void
	{
		$query = $_GET;
		unset($query['menuaction'], $query['click_history']);
		$query['print'] = 1;
		if (isset($_GET['click_history'])) $query['click_history'] = $_GET['click_history'];
		phpgw::redirect_link('/email/view/message', $query);
	}
}