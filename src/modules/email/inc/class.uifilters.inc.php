<?php

class uifilters
{
	var $public_functions = array('filters_list' => true, 'filters_edit' => true);

	public function filters_list(): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		phpgw::redirect_link('/email/view/filters', $query);
	}

	public function filters_edit(): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		phpgw::redirect_link('/email/view/filter', $query);
	}
}