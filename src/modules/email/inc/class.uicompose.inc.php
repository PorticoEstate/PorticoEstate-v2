<?php

class uicompose
{
	var $public_functions = array('compose' => true);

	public function compose($reuse_feed_args = ''): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		phpgw::redirect_link('/email/view/compose', $query);
	}
}