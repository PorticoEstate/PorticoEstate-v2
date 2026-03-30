<?php
/*	 * ************************************************************************\
	 * phpGroupWare - Admin config                                              *
	 * Written by Miles Lott <milosch@phpgroupware.org>                         *
	 * http://www.phpgroupware.org                                              *
	 * --------------------------------------------                             *
	 *  This program is free software; you can redistribute it and/or modify it *
	 *  under the terms of the GNU General Public License as published by the   *
	 *  Free Software Foundation; either version 2 of the License, or (at your  *
	 *  option) any later version.                                              *
	  \************************************************************************* */

/* $Id$ */

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\helpers\Template;
use App\modules\phpgwapi\services\Hooks;
use App\modules\phpgwapi\services\Translation;
use App\modules\phpgwapi\services\Config;


class admin_uiconfig
{

	public $public_functions = array(
		'index' => True,
		'phpinfo' => True,
	);
	private $appname;
	private $serverSettings;
	private $flags;
	private $hooks;
	private $translation;
	private $phpgwapi_common;
	private $apps;


	public function __construct()
	{
		$appname = Sanitizer::get_var('appname', 'string');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->flags = Settings::getInstance()->get('flags');
		Settings::getInstance()->update('flags', ['currentapp' => $appname]);
		$this->apps = Settings::getInstance()->get('apps');
		$this->appname = $appname;
		$acl = Acl::getInstance();
		$this->hooks = new Hooks();
		$this->translation = Translation::getInstance();
		$this->phpgwapi_common = new \phpgwapi_common();

		$is_admin	 = $acl->check('run', Acl::READ, 'admin');
		$local_admin = false;
		if (!$is_admin)
		{
			if ($acl->check('admin', Acl::ADD, $this->appname))
			{
				$local_admin = true;
			}
		}

		if (!$is_admin && !$local_admin)
		{
			phpgw::no_access();
		}
	}

	function phpinfo()
	{
		if (isset($_GET['noheader']) && $_GET['noheader'])
		{
			$this->flags = array(
				'nofooter'		 => true,
				'noframework'	 => true,
				'noheader'		 => true,
				'nonavbar'		 => true,
			);
		}
		$this->flags['menu_selection']	 = "admin::admin::phpinfo";
		Settings::getInstance()->set('flags', $this->flags);


		if (Sanitizer::get_var('noheader', 'bool', 'GET') && !Sanitizer::get_var('iframe', 'bool', 'GET'))
		{
			$close = lang('close window');

			echo <<<HTML
					<div style="text-align: center;">
						<a href="javascript:window.close();">{$close}</a>
					</div>
HTML;
		}

		if (function_exists('phpinfo'))
		{
			if (Sanitizer::get_var('get_info', 'bool', 'GET'))
			{
				phpinfo();
			}
			else
			{
				$this->phpgwapi_common->phpgw_header(true);

				$link = phpgw::link('/index.php', array('menuaction' => 'admin.uiconfig.phpinfo', 'get_info' => true, 'noheader' => true, 'iframe' => true));
				echo <<<HTML

				<script>
					function resizeIframe(obj)
					{
						obj.style.height = obj.contentWindow.document.documentElement.scrollHeight + 'px';
					}
				</script>

				<iframe id="phpinfo" src="{$link}" width="100%" frameborder="0" scrolling="no" onload="resizeIframe(this)" ></iframe>
HTML;
			}
		}
		else
		{
			$error = lang('phpinfo is not available on this system!');
			echo <<<HTML
					<div class="error"><h1>$error</h1><div>
		
HTML;
		}

	}



	function index()
	{
		$errors	 = '';
		$referer = Sanitizer::get_var('referer', 'url', 'GET');

		if ($referer)
		{
			$_redir = $referer;
			Cache::session_set('admin_config', 'session_data', $referer);
		}
		else
		{
			$referer = Cache::session_get('admin_config', 'session_data');
			if ($referer == -1)
			{
				$referer = '';
			}
			$_redir = $referer ? $referer : phpgw::link('/index.php', array('menuaction' => 'admin.uimainscreen.mainscreen'));
		}

		$appname	= $this->appname;
		Settings::getInstance()->update('flags', ['menu_selection' => "admin::{$appname}::index"]);

		$this->apps['manual']['app'] = $appname; // override the appname fetched from the referer for the manual.
		Settings::getInstance()->set('apps', $this->apps);

		switch ($appname)
		{
			case 'admin':
				//case 'preferences':
				//$appname = 'preferences';
			case 'addressbook':
			case 'calendar':
			case 'email':
			case 'nntp':
				/*
					  Other special apps can go here for now, e.g.:
					  case 'bogusappname':
					 */
				$config_appname	 = 'phpgwapi';
				break;
			case 'phpgwapi':
			case '':
				/* This keeps the admin from getting into what is a setup-only config */
				Header('Location: ' . str_replace('&amp;', '&', $_redir));
				break;
			default:
				$config_appname	 = $appname;
				break;
		}

		$t = Template::getInstance();
		$t->set_root($this->phpgwapi_common->get_tpl_dir($appname));

		$t->set_file(array('config' => 'config.tpl'));
		$t->set_block('config', 'body', 'body');


		$c = new Config($config_appname);

		$c->read();

		if ($c->config_data)
		{
			$current_config = $c->config_data;
		}

		if (isset($_POST['cancel']) && $_POST['cancel'])
		{
			Header('Location: ' . str_replace('&amp;', '&', $_redir));
		}

		$errors = '';
		if (isset($_POST['submit']) && $_POST['submit'])
		{
			/* Load hook file with functions to validate each config (one/none/all) */
			$this->hooks->single('config_validate', $appname);

			//while (list($key,$config) = each($_POST['newsettings']))
			if (is_array($_POST['newsettings']))
			{
				foreach ($_POST['newsettings'] as $key => $config)
				{
					if ($config || $config === '0')
					{
						if (isset($this->serverSettings['found_validation_hook']) && $this->serverSettings['found_validation_hook'] && function_exists($key))
						{
							call_user_func($key, $config);
							if ($GLOBALS['config_error'])
							{
								$errors					 .= lang($GLOBALS['config_error']) . '&nbsp;';
								$GLOBALS['config_error'] = False;
							}
							else
							{
								$c->config_data[$key] = $config;
							}
						}
						else
						{
							$c->config_data[$key] = $config;
						}
					}
					else
					{
						/* don't erase passwords, since we also don't print them */
						if (!preg_match('/passwd/', $key) && !preg_match('/password/', $key) && !preg_match('/root_pw/', $key))
						{
							unset($c->config_data[$key]);
						}
					}
				}
			}
			if (isset($this->serverSettings['found_validation_hook']) && $this->serverSettings['found_validation_hook'] && function_exists('final_validation'))
			{
				final_validation($_POST['newsettings']);
				//FIXME: this is a hack to get the error message from the final_validation hook

				if ($GLOBALS['config_error'])
				{
					$errors					 .= lang($GLOBALS['config_error']) . '&nbsp;';
					$GLOBALS['config_error'] = False;
				}
				unset($this->serverSettings['found_validation_hook']);
			}

			$c->save_repository(True);

			if (!$errors)
			{
				Cache::session_set('admin_config', 'session_data', -1);
				Header('Location: ' . str_replace('&amp;', '&', $_redir));
				exit;
			}
		}

		if (isset($errors) && $errors)
		{
			$t->set_var(array(
				'error'			 => lang('Error: %1', $errors),
				'error_class'	 => 'error'
			));
			unset($errors);
			unset($GLOBALS['config_error']);
		}
		else
		{
			$t->set_var(array(
				'error'			 => '',
				'error_class'	 => ''
			));
		}


		$t->set_var(array(
			'action_url'	 => phpgw::link('/index.php', array(
				'menuaction' => 'admin.uiconfig.index',
				'appname' => $appname
			)),
			'lang_cancel'	 => lang('cancel'),
			'lang_submit'	 => lang('save'),
			'title'			 => lang('Site Configuration'),
		));

		//		$t->unknown_regexp = 'loose';
		$vars = $t->get_undefined('body');
		//		$t->unknown_regexp = '';

		$this->hooks->single('config', $appname);

		if (is_array($vars))
		{
			foreach ($vars as $value)
			{
				$valarray	 = explode('_', $value);
				$type		 = $valarray[0];
				$new		 = array();
				$newval		 = '';

				while ($chunk = next($valarray))
				{
					$new[] = $chunk;
				}
				$newval = implode(' ', $new);

				switch ($type)
				{
					case 'lang':
						$t->set_var($value, $this->translation->translate($newval, array(), false, $appname));
						break;
					case 'value':
						$newval = preg_replace('/ /', '_', $newval);
						/* Don't show passwords in the form */
						if (!isset($current_config[$newval]) || preg_match('/passwd/', $value) || preg_match('/password/', $value) || preg_match('/root_pw/', $value))
						{
							$t->set_var($value, '');
						}
						else
						{
							$t->set_var($value, (isset($current_config[$newval]) ? $current_config[$newval] : ''));
						}
						break;
					case 'checked':
						/* '+' is used as a delimiter for the check value */
						list($newvalue, $check) = preg_split('/\+/', $newval);
						$newval = preg_replace('/ /', '_', $newvalue);
						if ($current_config[$newval] == $check)
						{
							$t->set_var($value, ' checked');
						}
						else
						{
							$t->set_var($value, '');
						}
						break;
					case 'selected':
						$configs = array();
						$config	 = '';
						$newvals = explode(' ', $newval);
						$setting = end($newvals);
						for ($i = 0; $i < (count($newvals) - 1); $i++)
						{
							$configs[] = $newvals[$i];
						}
						$config = implode('_', $configs);
						/* echo $config . '=' . $current_config[$config]; */
						if (isset($current_config[$config]) && $current_config[$config] == $setting)
						{
							$t->set_var($value, ' selected');
						}
						else
						{
							$t->set_var($value, '');
						}
						break;
					case 'hook':
						$newval = preg_replace('/ /', '_', $newval);
						if (function_exists($newval))
						{
							$t->set_var($value, $newval($current_config));
						}
						else
						{
							$t->set_var($value, '');
						}
						break;
					default:
						$t->set_var($value, '');
						break;
				}
			}
		}
		$this->phpgwapi_common->phpgw_header(true);
		$t->pfp('out', 'config');
	}
}
