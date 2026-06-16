<?php

/**************************************************************************\
 * phpGroupWare - Administration                                            *
 * http://www.phpgroupware.org                                              *
 * --------------------------------------------                             *
 *  This program is free software; you can redistribute it and/or modify it *
 *  under the terms of the GNU General Public License as published by the   *
 *  Free Software Foundation; either version 2 of the License, or (at your  *
 *  option) any later version.                                              *
	\**************************************************************************/

/* $Id$ */

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use App\helpers\Template;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Twig;


class admin_uiaccess_history
{
	public $public_functions = array(
		'list_history' => True
	);

	private $flags;
	private $phpgwapi_common;

	public function __construct()
	{
		$this->flags = Settings::getInstance()->get('flags');
		$this->phpgwapi_common = new \phpgwapi_common();
	}

	public function list_history()
	{
		$acl = Acl::getInstance();
		if ($acl->check('access_log_access', 1, 'admin'))
		{
			phpgw::redirect_link('/index.php');
		}

		$bo         = createobject('admin.boaccess_history');
		$nextmatches = createobject('phpgwapi.nextmatchs');
		$_accounts = new Accounts();

		$account_id	= Sanitizer::get_var('account_id', 'int', 'REQUEST', 0);
		$start		= Sanitizer::get_var('start', 'int', 'GET', 0);
		$sort		= Sanitizer::get_var('sort', 'int', 'POST', 0);
		$order		= Sanitizer::get_var('order', 'int', 'POST', 0);
		$query		= Sanitizer::get_var('query');

		if (!$account_id && $query)
		{
			$account_id = $_accounts->name2id($query);
		}

		$this->flags['app_header'] = lang('Admin') . ' - ' . lang('View access log');
		$this->flags['menu_selection'] = 'admin::admin::access_log';
		Settings::getInstance()->set('flags', $this->flags);

		phpgw::import_class('phpgwapi.jquery');
		phpgwapi_jquery::load_widget('select2');

		$this->phpgwapi_common->phpgw_header(true);

		$total_records = $bo->total($account_id);

		// Prepare data for Twig template
		$templateData = array(
			'nextmatchs_left'	 => $nextmatches->left('/index.php', $start, $total_records, '&menuaction=admin.uiaccess_history.list_history&account_id=' . $account_id),
			'nextmatchs_right'	 => $nextmatches->right('/index.php', $start, $total_records, '&menuaction=admin.uiaccess_history.list_history&account_id=' . $account_id),
			'showing'			 => $nextmatches->show_hits($total_records, $start)
			// Lang strings will be handled in the template
		);
		
		$__account	 = $_accounts->get($account_id);
		if ($__account->enabled)
		{
			$accounts[]	 = array(
				'id'	 => $__account->id,
				'name'	 => $__account->__toString()
			);
		}

		$account_list	 = "<div><form class='pure-form' method='POST' action=''>";
		$account_list	 .= '<select name="account_id" id="account_id" onChange="this.form.submit();" style="width:50%;">';
		$account_list	 .= "<option value=''>" . lang('select user') . '</option>';
		foreach ($accounts as $account)
		{
			$account_list .= "<option value='{$account['id']}'";
			if ($account['id'] == $account_id)
			{
				$account_list .= ' selected';
			}
			$account_list .= "> {$account['name']}</option>\n";
		}
		$account_list	 .= '</select>';
		$account_list	 .= '<noscript><input type="submit" name="user" value="Select"></noscript>';
		$account_list	 .= '</form></div>';

		$lang_user = lang('Search for a user');
		$account_list	 .= <<<HTML
					<script>
						var oArgs = {menuaction: 'preferences.boadmin_acl.get_users'};
						var strURL = phpGWLink('index.php', oArgs, true);

						$("#account_id").select2({
						  ajax: {
							url: strURL,
							dataType: 'json',
							delay: 250,
							data: function (params) {
							  return {
								query: params.term, // search term
								page: params.page || 1
							  };
							},
							cache: true
						  },
						  width: '50%',
						  placeholder: '{$lang_user}',
						  minimumInputLength: 2,
						  language: "no",
						  allowClear: true
						});

						$('#account_id').on('select2:open', function (e) {
							$(".select2-search__field").each(function()
							{
								if ($(this).attr("aria-controls") == 'select2-account_id-results')
								{
									$(this)[0].focus();
								}
							});
						});
					</script>
HTML;

		$templateData['select_user'] =  $account_list;

		if ($account_id)
		{
			$templateData['link_return_to_view_account'] = '<a href="' . phpgw::link(
				'/index.php',
				array(
					'menuaction' => 'admin.uiaccounts.view',
					'account_id' => $account_id
				)
			) . '">' . lang('Return to view account') . '</a>';
			$templateData['lang_last_x_logins'] = lang('Last %1 logins for %2', $total_records, $this->phpgwapi_common->grab_owner_name($account_id));
		}
		else
		{
			$templateData['lang_last_x_logins'] = lang('Last %1 logins', $total_records);
		}

		$templateData['actionurl'] = phpgw::link('/index.php', array('menuaction' => 'admin.uiaccess_history.list_history'));

		// Process records for the template
		$rows_access = '';
		$records = $bo->list_history($account_id, $start, $order, $sort);
		
		if (is_array($records))
		{
			foreach ($records as &$record)
			{
				$row_class = $nextmatches->alternate_row_class();

				// Prepare data for row block
				$rowData = array(
					'tr_class'    => $row_class,
					'row_loginid' => $record['loginid'],
					'row_ip'      => $record['ip'],
					'row_li'      => $record['li'],
					'row_lo'      => $record['account_id'] ? $record['lo'] : '<b>' . lang($record['sessionid']) . '</b>',
					'row_total'   => ($record['lo'] ? $record['total'] : '&nbsp;')
				);
				
				// Render the row block and append to rows
				$rows_access .= Twig::getInstance()->renderBlock('accesslog.html.twig', 'row', $rowData, 'admin');
			}
		}

		if (!$total_records && $account_id)
		{
			$row_class = $nextmatches->alternate_row_class();
			$rowEmptyData = array(
				'tr_class'    => $row_class,
				'row_message' => lang('No login history exists for this user')
			);
			$rows_access .= Twig::getInstance()->renderBlock('accesslog.html.twig', 'row_empty', $rowEmptyData, 'admin');
		}

		$loggedout = $bo->return_logged_out($account_id);

		if ($total_records)
		{
			$percent = round(($loggedout / $total_records) * 100);
		}
		else
		{
			$percent = '0';
		}

		$templateData['rows_access'] = $rows_access;
		$templateData['footer_total'] = lang('Total records') . ': ' . $total_records;

		if ($account_id)
		{
			$templateData['lang_percent'] = lang('Percent this user has logged out') . ': ' . $percent . '%';
		}
		else
		{
			$templateData['lang_percent'] = lang('Percent of users that logged out') . ': ' . $percent . '%';
		}

		// Render the template with Twig
		echo Twig::getInstance()->renderBlock('accesslog.html.twig', 'list', $templateData, 'admin');
	}
}
