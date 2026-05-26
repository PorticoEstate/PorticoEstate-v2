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
use App\modules\phpgwapi\services\Config;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Log;
use App\modules\phpgwapi\services\Twig;

class admin_uiloglevels
{
	public $public_functions = array(
		'edit_log_levels' => True
	);

	private $flags;
	private $serverSettings;
	private $apps;
	private $acl;
	private $module_list = '';
	private $module_add_row = '';
	private $user_list = '';
	private $user_add_row = '';


	public function __construct()
	{
		$this->acl = Acl::getInstance();
		if ($this->acl->check('error_log_access', 1, 'admin'))
		{
			phpgw::redirect_link('/index.php');
		}
		$this->flags = Settings::getInstance()->get('flags');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->apps = Settings::getInstance()->get('apps');

		$this->flags['menu_selection'] = 'admin::admin::log_levels';
		Settings::getInstance()->set('flags', $this->flags);
	}

	public function edit_log_levels()
	{
		if ($this->acl->check('error_log_access', 1, 'admin'))
		{
			phpgw::redirect_link('/index.php');
		}

		// If log_levels have ever been set before, go ahead and set them.
		// There's probably a more correct place to do this.

		if (!isset($this->serverSettings['log_levels']))
		{
			$this->serverSettings['log_levels'] = array('global_level' => 'E', 'module' => array(), 'user' => array());
			Settings::getInstance()->set('server', $this->serverSettings);
		}

		// If they've updated something, save the change.
		$level_type = Sanitizer::get_var('level_type');
		if ($level_type)
		{
			$level_key = Sanitizer::get_var('level_key');
			$new_level = Sanitizer::get_var($level_type . '_' . $level_key . '_select');
			$this->update_level($level_type, $level_key, $new_level);
		}
		else
		{
			$level_key = Sanitizer::get_var('module_add_name_select');
			if ($level_key)
			{
				$this->update_level('module', $level_key, Sanitizer::get_var('module_add_level_select'));
			}
			$level_key = Sanitizer::get_var('user_add_name_select');
			if ($level_key)
			{
				$this->update_level('user', $level_key, Sanitizer::get_var('user_add_level_select'));
			}
		}

		$this->flags['app_header'] = lang('Admin') . ' - ' . lang('Edit Log Levels');
		Settings::getInstance()->set('flags', $this->flags);

		(new \phpgwapi_common())->phpgw_header();
		echo parse_navbar();

		$this->add_modules_list();
		$this->add_users_list();

		$templateData = [
			'lang_set_levels' => lang("Set Logging Levels"),
			'lang_global_level' => lang("Global logging level"),
			'lang_module_level' => lang("Module Logging Levels"),
			'lang_user_level' => lang("User Logging Levels"),
			'global_option' => $this->create_select_box('global', '', $this->serverSettings['log_levels']['global_level']),
			'module_list' => $this->module_list,
			'module_add_row' => $this->module_add_row,
			'user_list' => $this->user_list,
			'user_add_row' => $this->user_add_row,
			'tr_class' => 'pure-table-odd'
		];

		// Render the template with Twig
		echo Twig::getInstance()->renderBlock('loglevels.html.twig', 'loglevels', $templateData, 'admin');
	}


	private function add_modules_list()
	{
		$apps_with_logging = $this->serverSettings['log_levels']['module'];
		$sorted_apps = array();
		$app_add_list = array();
		foreach ($this->apps as $app => $app_data)
		{
			$sorted_apps[$app] = $app_data['title'];
		}

		$sorted_apps['login'] = 'Login';

		asort($sorted_apps);

		$add_options = '';
		$tr_class = 'pure-table-odd';
		foreach ($sorted_apps as $app => $title)
		{
			if (isset($this->serverSettings['log_levels']['module'][$app]))
			{
				$rowData = array(
					'tr_class' 		=> $tr_class,
					'type'   		=> 'module',
					'module_name'   => $title,
					'module_option' => $this->create_select_box('module', $app, $this->serverSettings['log_levels']['module'][$app]),
					'remove_url' 	=> phpgw::link('/index.php', array('menuaction' => 'admin.uiloglevels.edit_log_levels', 'level_type' => 'module', 'level_key' => $app)),
					'lang_remove'   => lang('remove')
					);
				
				// Render module row with Twig
				$this->module_list .= Twig::getInstance()->renderBlock('loglevels.html.twig', 'module', $rowData, 'admin');
				
				if ($tr_class == 'pure-table-odd')
				{
					$tr_class = '';
				}
				else
				{
					$tr_class = 'pure-table-odd';
				}
				$add_options .= "<option disabled=\"disabled\">{$title}</option>\n";
			}
			else
			{
				$add_options .= "<option value=\"{$app}\">{$title}</option>\n";
			}
		}

		if ($add_options)
		{
			$rowData = array(
				'tr_class' 		=> $tr_class,
				'type'   		=> 'module',
				'module_add_link' => phpgw::link('/index.php', array('menuaction' => 'admin.uiloglevels.edit_log_levels')),
				'lang_add' => lang('add'),
				'module_add_options' => $add_options,
				'lang_fatal'    => lang('fatal'),
				'lang_error'    => lang('error'),
				'lang_warn' 	=> lang('warn'),
				'lang_notice' 	=> lang('notice'),
				'lang_info'    	=> lang('info'),
				'lang_debug'    => lang('debug'),
				'lang_add'   	=> lang('add')
			);
			
			// Render module add row with Twig
			$this->module_add_row = Twig::getInstance()->renderBlock('loglevels.html.twig', 'module_add', $rowData, 'admin');
		}
	}

	private function add_users_list()
	{
		$add_options = '';
		$tr_class = 'pure-table-odd';
		$accounts = (new Accounts())->get_list('accounts');
		foreach ($accounts as $account)
		{
			$account_lid = $account->lid;
			$name = (string) $account;
			if (isset($this->serverSettings['log_levels']['user'][$account_lid]))
			{
				$rowData = array(
					'tr_class' 		=> $tr_class,
					'module_name'   => (string) $account,
					'module_option' => $this->create_select_box('user', $account_lid, $this->serverSettings['log_levels']['user'][$account_lid]),
					'remove_url' => phpgw::link('/index.php', array('menuaction' => 'admin.uiloglevels.edit_log_levels', 'level_type' => 'user', 'level_key' => $account_lid)),
					'lang_remove'   => lang('remove')
				);

				// Render user row with Twig
				$this->user_list .= Twig::getInstance()->renderBlock('loglevels.html.twig', 'module', $rowData, 'admin');

				if ($tr_class == 'pure-table-odd')
				{
					$tr_class = '';
				}
				else
				{
					$tr_class = 'pure-table-odd';
				}
			}
			else
			{
				$add_options .= "<option value=\"{$account_lid}\">{$name}</option>\n";
			}
		}

		if ($add_options)
		{
			$rowData = array(
				'type'   		=> 'user',
				'tr_class' 		=> $tr_class,
				'module_add_link' => phpgw::link('/index.php', array('menuaction' => 'admin.uiloglevels.edit_log_levels')),
				'lang_add' => lang('add'),
				'module_add_options' => $add_options,
				'lang_fatal'    => lang('fatal'),
				'lang_error'    => lang('error'),
				'lang_warn' 	=> lang('warn'),
				'lang_info'    	=> lang('info'),
				'lang_debug'    => lang('debug'),
				'lang_add'   	=> lang('add')
			);
			
			// Render user add row with Twig
			$this->user_add_row = Twig::getInstance()->renderBlock('loglevels.html.twig', 'module_add', $rowData, 'admin');
		}
	}

	private function create_select_box($level_type, $level_key, $current_level)
	{
		$select_name = "{$level_type}_{$level_key}_select";

		$selectData = array(
			'level_type'	=> $level_type,
			'level_key'		=> $level_key,
			'select_link'	=> phpgw::link('/index.php', array('menuaction' => 'admin.uiloglevels.edit_log_levels')),
			'select_name'	=> $select_name,
			'F_selected'	=> ($current_level === 'F'),
			'E_selected'	=> ($current_level === 'E'),
			'N_selected'	=> ($current_level === 'N'),
			'W_selected'	=> ($current_level === 'W'),
			'I_selected'	=> (!$current_level || $current_level === 'I'),
			'D_selected'	=> ($current_level === 'D'),
			'S_selected'	=> ($current_level === 'S'),
			'DP_selected'	=> ($current_level === 'DP'),
			'A_selected'	=> ($current_level === 'A')
		);

		// Render the select box with Twig
		return Twig::getInstance()->renderBlock('log_level_select.html.twig', 'log_level_select', $selectData, 'admin');
	}

	private function update_level($level_type, $level_key, $new_level)
	{
		$log = new Log();

		if ($new_level)
		{
			if ($level_type == "global")
			{
				$log->info(array(
					'text' => "Setting %1 log level to %2",
					'p1' => $level_type,
					'p2' => $new_level,
					'line' => __LINE__,
					'file' => __FILE__
				));

				$this->serverSettings['log_levels']['global_level'] = $new_level;
			}
			else
			{
				$log->info(array(
					'text' => "Setting log level for %1 %2 to %3",
					'p1' => $level_type,
					'p2' => $level_key,
					'p3' => $new_level,
					'line' => __LINE__,
					'file' => __FILE__
				));

				$this->serverSettings['log_levels'][$level_type][$level_key] = $new_level;
			}
		}
		else
		{
			$log->info(array(
				'text' => "Removing log level for %1 %2",
				'p1' => $level_type,
				'p2' => $level_key,
				'line' => __LINE__,
				'file' => __FILE__
			));

			unset($this->serverSettings['log_levels'][$level_type][$level_key]);
		}

		Settings::getInstance()->set('server', $this->serverSettings);

		// save it...  It would be nice if phpgwapi.config had an method for just saving one setting.

		$c = new Config('phpgwapi');

		$c->read();
		$c->value('log_levels', $this->serverSettings['log_levels']);
		$c->save_repository();
	}
}
