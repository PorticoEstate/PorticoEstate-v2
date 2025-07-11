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
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Hooks;


class admin_uilog
{
	public $public_functions = array(
		'list_log'	=> true,
		'purge_log'	=> true
	);

	private $flags;
	private $hooks;
	private $phpgwapi_common;
	private $acl;


	public function __construct()
	{
		$this->flags = Settings::getInstance()->get('flags');
		$this->hooks = new Hooks();
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->acl = Acl::getInstance();

		if ($this->acl->check('error_log_access', 1, 'admin'))
		{
			phpgw::redirect_link('/index.php');
		}

		$this->flags['menu_selection'] = 'admin::admin::error_log';
		Settings::getInstance()->set('flags', $this->flags);
	}

	public function list_log()
	{
		if ($this->acl->check('error_log_access', 1, 'admin'))
		{
			phpgw::redirect_link('/index.php');
		}

		$account_id		= Sanitizer::get_var('account_id', 'int');
		$date			= Sanitizer::get_var('date', 'date');
		$date_string	= Sanitizer::get_var('date', 'string');
		$start			= Sanitizer::get_var('start', 'int');
		$sort			= Sanitizer::get_var('sort', 'int');
		$order			= Sanitizer::get_var('order', 'int');


		$this->flags['app_header'] = lang('Admin') . ' - ' . lang('View error log');
		if ($account_id)
		{
			$this->flags['app_header'] .= ' ' . lang('for') . ' ' . $this->phpgwapi_common->grab_owner_name($account_id);
		}

		Settings::getInstance()->set('flags', $this->flags);

		phpgw::import_class('phpgwapi.jquery');
		phpgwapi_jquery::load_widget('select2');
		createObject('phpgwapi.jqcal')->add_listener('date');

		$this->phpgwapi_common->phpgw_header(true);

		$bo = createObject('admin.bolog');
		$nextmatches = createObject('phpgwapi.nextmatchs');

		$t   = new Template();
		$t->set_root(PHPGW_APP_TPL);

		$t->set_file(array(
			'errorlog'		=> 'errorlog_view.tpl',
			'form_button'	=> 'form_button_script.tpl'
		));

		$t->set_block('errorlog', 'list');
		$t->set_block('errorlog', 'row');
		$t->set_block('errorlog', 'row_empty');

		$total_records = $bo->total($account_id, $date);

		$var = array(
			'nextmatchs_left'  => $nextmatches->left('/index.php', $start, $total_records, "&menuaction=admin.uilog.list_log&account_id={$account_id}&date={$date_string}"),
			'nextmatchs_right' => $nextmatches->right('/index.php', $start, $total_records, "&menuaction=admin.uilog.list_log&account_id={$account_id}&date={$date_string}"),
			'showing'          => $nextmatches->show_hits($total_records, $start),
			'lang_loginid'     => lang('LoginID'),
			'lang_date'        => lang('time'),
			'lang_app'         => lang('module'),
			'lang_severity'    => lang('severity'),
			'lang_line'        => lang('line'),
			'lang_file'        => lang('file'),
			'lang_message'     => lang('log message'),
			'lang_total'       => lang('Total')
		);

		$__account	 = (new Accounts())->get($account_id);
		if ($__account->enabled)
		{
			$accounts[]	 = array(
				'id'	 => $__account->id,
				'name'	 => $__account->__toString()
			);
		}

		phpgw::import_class('phpgwapi.jquery');
		phpgwapi_jquery::load_widget('select2');

		$account_list	 = "<div>";
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
		$account_list	 .= '</div>';

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
		$var['select_user'] =  $account_list;
		$var['value_date']	= Sanitizer::get_var('date');
		$t->set_var($var);

		$records = $bo->list_log($account_id, $start, $order, $sort, $date);
		if (!is_array($records) || !count($records))
		{
			$t->set_var(array(
				'row_message'	=> lang('No error log records exist for this user'),
				'tr_class'		=> 'row_on'
			));
			$t->fp('rows_access', 'row_empty', true);
		}
		else
		{
			$tr_class = '';
			foreach ($records as $record)
			{
				$tr_class = $nextmatches->alternate_row_class($tr_class);
				$t->set_var(array(
					'row_date' 		=> $record['log_date'],
					'row_loginid'   => $record['log_account_lid'],
					'row_app'      	=> $record['log_app'],
					'row_severity'  => $record['log_severity'],
					'row_file'      => $record['log_file'],
					'row_line'      => $record['log_line'],
					'row_message'   => htmlentities(str_replace("''", "'", $record['log_msg'])),
					'tr_class'		=> $tr_class
				));
				$t->parse('rows_access', 'row', true);
			}
		}

		if ($total_records)
		{
			if ($account_id)
			{
				$var = array(
					'submit_button'			=> lang('Delete'),
					'action_url_button'     => phpgw::link('/index.php', array('menuaction' => 'admin.uilog.purge_log', 'account_id' => $account_id)),
					'action_text_button'    => ' ' . lang('Delete all log records for %1', $this->phpgwapi_common->grab_owner_name($account_id)),
					'action_confirm_button' => '',
					'action_extra_field'    => ''
				);
			}
			else
			{
				$var = array(
					'submit_button'			=> lang('Delete'),
					'action_url_button'     => phpgw::link('/index.php', array('menuaction' => 'admin.uilog.purge_log')),
					'action_text_button'    => ' ' . lang('Delete all log records'),
					'action_confirm_button' => '',
					'action_extra_field'    => ''
				);
			}
			$t->set_var($var);
			$var['purge_log_button'] = $t->fp('button', 'form_button', true);

			$t->set_var($var);
		}

		if ($account_id)
		{
			$account_name = $this->phpgwapi_common->grab_owner_name($account_id);
			$var = array('footer_total' => lang('Total records for %1 : %2', $account_name, $total_records));
		}
		else
		{
			$var = array('footer_total' => lang('Total records: %1', $total_records));
		}


		//$t->set_var($var);
		$t->pfp('out', 'list');
	}

	public function purge_log()
	{
		if ($this->acl->check('error_log_access', 1, 'admin'))
		{
			phpgw::redirect_link('/index.php');
		}
		execMethod('admin.bolog.purge_log', Sanitizer::get_var('account_id', 'int'));
		phpgw::redirect_link('/index.php', array('menuaction' => 'admin.uilog.list_log'));
	}
}
