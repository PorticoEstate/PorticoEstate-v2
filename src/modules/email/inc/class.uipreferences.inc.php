<?php

class email_uipreferences
{
	var $public_functions = array(
		'preferences' => true,
		'ex_accounts_edit' => true,
		'ex_accounts_list' => true,
		'preferences_default_acct_zero' => true,
	);

	public function preferences(): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		if (isset($query['ex_acctnum']) && (string) $query['ex_acctnum'] !== '' && (string) $query['ex_acctnum'] !== '0')
		{
			phpgw::redirect_link('/email/view/account', $query);
		}
		phpgw::redirect_link('/email/view/preferences', $query);
	}

	public function ex_accounts_edit(): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		phpgw::redirect_link('/email/view/account', $query);
	}

	public function ex_accounts_list(): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		phpgw::redirect_link('/email/view/accounts', $query);
	}

	public function preferences_default_acct_zero(): void
	{
		$query = $_GET;
		unset($query['menuaction']);
		phpgw::redirect_link('/email/view/preferences', $query);
	}
}