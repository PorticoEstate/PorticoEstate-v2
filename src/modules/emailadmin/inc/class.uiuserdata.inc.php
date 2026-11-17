<?php

class uiuserdata
{
	var $public_functions = array(
		'editUserData' => true
	);

	function editUserData()
	{
		$accountID = (int) ($_GET['account_id'] ?? 0);
		if ($accountID < 1)
		{
			\phpgw::redirect_link('/admin/accounts.php');
			return;
		}

		\phpgw::redirect_link('/emailadmin/view/users/' . $accountID . '/edit');
	}
}
