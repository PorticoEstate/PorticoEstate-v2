<?php

/**
 * Setup
 *
 * @copyright Copyright (C) 2000-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package setup
 * @version $Id$
 */

namespace App\modules\setup\controllers;

use App\Database\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\setup\Setup;
use App\modules\phpgwapi\services\setup\Detection;
use App\modules\phpgwapi\services\setup\Process;
use App\modules\phpgwapi\services\setup\Html;
use App\helpers\Template;
use App\modules\phpgwapi\services\setup\SetupTranslation;
use App\modules\phpgwapi\services\Sanitizer;

class Applications
{
	/**
	 * @var object
	 */
	private $db;
	private $detection;
	private $process;
	private $html;
	private $setup;
	private $setup_tpl;

	public function __construct()
	{
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

		//setup_info
		Settings::getInstance()->set('setup_info', []); //$GLOBALS['setup_info']
		//setup_data
		Settings::getInstance()->set('setup', []); //$GLOBALS['phpgw_info']['setup']

		$this->db = Db::getInstance();
		$this->detection = new Detection();
		$this->process = new Process();
		$this->html = new Html();
		$this->setup = new Setup();

		$flags = array(
			'noheader' 		=> True,
			'nonavbar'		=> True,
			'currentapp'	=> 'home',
			'noapi'			=> True,
			'nocachecontrol' => True
		);
		Settings::getInstance()->set('flags', $flags);


		// Check header and authentication
		if (!$this->setup->auth('Config'))
		{
			Header('Location: ../setup');
			exit;
		}

		$tpl_root = $this->html->setup_tpl_dir('setup');
		$this->setup_tpl = new Template($tpl_root);
		$this->setup_tpl->set_file(array(
			'T_head' => 'head.tpl',
			'T_footer' => 'footer.tpl',
			'T_alert_msg' => 'msg_alert_msg.tpl',
			'T_login_main' => 'login_main.tpl',
			'T_login_stage_header' => 'login_stage_header.tpl',
			'T_setup_main' => 'applications.tpl'
		));


		$this->setup_tpl->set_block('T_login_stage_header', 'B_multi_domain', 'V_multi_domain');
		$this->setup_tpl->set_block('T_login_stage_header', 'B_single_domain', 'V_single_domain');
		$this->setup_tpl->set_block('T_setup_main', 'header', 'header');
		$this->setup_tpl->set_block('T_setup_main', 'app_header', 'app_header');
		$this->setup_tpl->set_block('T_setup_main', 'apps', 'apps');
		$this->setup_tpl->set_block('T_setup_main', 'detail', 'detail');
		$this->setup_tpl->set_block('T_setup_main', 'table', 'table');
		$this->setup_tpl->set_block('T_setup_main', 'hook', 'hook');
		$this->setup_tpl->set_block('T_setup_main', 'dep', 'dep');
		$this->setup_tpl->set_block('T_setup_main', 'app_footer', 'app_footer');
		$this->setup_tpl->set_block('T_setup_main', 'submit', 'submit');
		$this->setup_tpl->set_block('T_setup_main', 'footer', 'footer');
		$this->setup_tpl->set_var('lang_cookies_must_be_enabled', $this->setup->lang('<b>NOTE:</b> You must have cookies enabled to use setup and header admin!'));

		$this->html->set_tpl($this->setup_tpl);
	}

	/**
	 * Parse dependencies
	 * 
	 * @param array $depends
	 * @param boolean $main Return a string when true otherwise an array
	 * @return string|array Dependency string or array
	 */
	function parsedep($depends, $main = True)
	{
		$ret = array();
		foreach ($depends as $b)
		{
			$depstring = '';
			foreach ($b as $c => $d)
			{
				if (is_array($d))
				{
					$depstring .= "($c : " . implode(', ', $d) . ')';
					$depver[] = $d;
				}
				else
				{
					$depstring .= $d . " ";
					$depapp[] = $d;
				}
			}
			$ret[] = $depstring;
		}
		if ($main)
		{
			return implode("<br/>\n", $ret);
		}
		else
		{
			return array($depapp, $depver);
		}
	}


	public function index()
	{

		if (\Sanitizer::get_var('cancel', 'bool', 'POST'))
		{
			Header('Location: ../setup');
			exit;
		}

		@set_time_limit(0);

		$DEBUG = \Sanitizer::get_var('debug', 'bool');

		$this->setup->loaddb();

		$serverSettings = Settings::getInstance()->get('server');
		$setup_data = Settings::getInstance()->get('setup');
		$setup_info = Settings::getInstance()->get('setup_info');


		$setup_data['stage']['db'] = $this->detection->check_db();

		$setup_info = $this->detection->get_versions();
		//var_dump($setup_info);exit;
		$setup_info = $this->detection->get_db_versions($setup_info);
		//var_dump($setup_info);exit;
		$setup_info = $this->detection->compare_versions($setup_info);
		//var_dump($setup_info);exit;
		$setup_info = $this->detection->check_depends($setup_info);
		//var_dump($setup_info);exit;
		ksort($setup_info);

		$db_config = $this->db->get_config();
		$header = '';

		if (\Sanitizer::get_var('submit', 'string', 'POST'))
		{
			$header .= $this->html->get_header($this->setup->lang('Application Management'), False, 'config', $this->db->get_domain() . '(' . $db_config['db_type'] . ')');
			$this->setup_tpl->set_var('description', $this->setup->lang('App install/remove/upgrade') . ':');
			$header .= $this->setup_tpl->fp('out', 'header');

			$appname = \Sanitizer::get_var('appname', 'string', 'POST');
			$remove  = \Sanitizer::get_var('remove', 'string', 'POST');
			$install = \Sanitizer::get_var('install', 'string', 'POST');
			$upgrade = \Sanitizer::get_var('upgrade', 'string', 'POST');

			if (!isset($this->process->oProc) || !$this->process->oProc)
			{
				$this->process->init_process();
			}

			//$this->process->add_credential('property');
			if (!empty($remove) && is_array($remove))
			{
				$this->process->oProc->m_odb->transaction_begin();
				foreach ($remove as $appname => $key)
				{
					$header .=  '<h3>' . $this->setup->lang('Processing: %1', $this->setup->lang($appname)) . "</h3>\n<ul>";
					$terror = array($setup_info[$appname]);

					if (
						isset($setup_info[$appname]['views'])
						&& $setup_info[$appname]['views']
					)
					{
						$this->process->dropviews($terror, $DEBUG);
						$header .=  '<li>' . $this->setup->lang('%1 views dropped', $this->setup->lang($appname)) . ".</li>\n";
					}

					if (
						isset($setup_info[$appname]['tables'])
						&& $setup_info[$appname]['tables']
					)
					{
						$this->process->droptables($terror, $DEBUG);
						$header .=  '<li>' . $this->setup->lang('%1 tables dropped', $this->setup->lang($appname)) . ".</li>\n";
					}

					$this->setup->deregister_app($appname);
					$header .=  '<li>' . $this->setup->lang('%1 deregistered', $this->setup->lang($appname)) . ".</li>\n";

					if (
						isset($setup_info[$appname]['hooks'])
						&& $setup_info[$appname]['hooks']
					)
					{
						$this->setup->deregister_hooks($appname);
						$header .=  '<li>' . $this->setup->lang('%1 hooks deregistered', $this->setup->lang($appname)) . ".</li>\n";
					}

					$terror = $this->process->drop_langs($terror, $DEBUG);
					$header .=  '<li>' . $this->setup->lang('%1 translations removed', $appname) . ".</li>\n</ul>\n";
				}
				$this->process->oProc->m_odb->transaction_commit();
			}

			if (!empty($install) && is_array($install))
			{
				$this->process->oProc->m_odb->transaction_begin();
				foreach ($install as $appname => $key)
				{
					$header .=  '<h3>' . $this->setup->lang('Processing: %1', $this->setup->lang($appname)) . "</h3>\n<ul>";
					$terror = array($setup_info[$appname]);

					if (
						isset($setup_info[$appname]['tables'])
						&& is_array($setup_info[$appname]['tables'])
					)
					{
						$terror = $this->process->current($terror, $DEBUG);
						$header .=  "<li>{$setup_info[$appname]['name']} "
							. $this->setup->lang('tables installed, unless there are errors printed above') . ".</h3>\n";
						$terror = $this->process->default_records($terror, $DEBUG);
						$header .=  '<li>' . $this->setup->lang('%1 default values processed', $this->setup->lang($appname)) . ".</li>\n";
					}
					else
					{
						if ($this->setup->app_registered($appname))
						{
							$this->setup->update_app($appname);
						}
						else
						{
							$this->setup->register_app($appname);
							$header .=  '<li>' . $this->setup->lang('%1 registered', $this->setup->lang($appname)) . ".</li>\n";

							// Default values has be processed - even for apps without tables - after register for locations::add to work
							$terror = $this->process->default_records($terror, $DEBUG);
							$header .=  '<li>' . $this->setup->lang('%1 default values processed', $this->setup->lang($appname)) . ".</li>\n";
						}
						if (
							isset($setup_info[$appname]['hooks'])
							&& is_array($setup_info[$appname]['hooks'])
						)
						{
							$this->setup->register_hooks($appname);
							$header .=  '<li>' . $this->setup->lang('%1 hooks registered', $this->setup->lang($appname)) . ".</li>\n";
						}
					}
					$force_en = False;
					if ($appname == 'phpgwapi')
					{
						$force_en = true;
					}
					$terror = $this->process->add_langs($terror, $DEBUG, $force_en);
					$header .=  '<li>' . $this->setup->lang('%1 translations added', $this->setup->lang($appname)) . ".</li>\n</ul>\n";
					// Add credentials to admins
					$this->process->add_credential($appname);
				}
				$this->process->oProc->m_odb->transaction_commit();
			}

			if (!empty($upgrade) && is_array($upgrade))
			{
				foreach ($upgrade as $appname => $key)
				{
					$header .=  '<h3>' . $this->setup->lang('Processing: %1', $this->setup->lang($appname)) . "</h3>\n<ul>";
					$terror = array();
					$terror[] = $setup_info[$appname];

					$this->process->upgrade($terror, $DEBUG);
					if (isset($setup_info[$appname]['tables']))
					{
						$header .=  '<li>' . $this->setup->lang('%1 tables upgraded', $this->setup->lang($appname)) . ".</li>";
						// The process_upgrade() function also handles registration
					}
					else
					{
						$header .=  '<li>' . $this->setup->lang('%1 upgraded', $this->setup->lang($appname)) . ".</li>";
					}

					// Sigurd sep 2010: very slow - run 'Manage Languages' from setup instead. 
					//	$terror = $this->process->upgrade_langs($terror,$DEBUG);
					//	echo '<li>' . $this->setup->lang('%1 translations upgraded', $this->setup->lang($appname)) . ".</li>\n</ul>\n";
					$header .=  "<li>To upgrade languages - run <b>'Manage Languages'</b> from setup</li>\n</ul>\n";
				}
			}

			$header .=  "<h3><a href=\"applications?debug={$DEBUG}\">" . $this->setup->lang('Done') . "</h3>\n";
			$footer = $this->setup_tpl->fp('out', 'footer');

			return $header . $footer;

			exit;
		}
		else
		{
			$header .= $this->html->get_header($this->setup->lang('Application Management'), False, 'config', $this->db->get_domain() . '(' . $db_config['db_type'] . ')');
		}

		$detail = \Sanitizer::get_var('detail', 'string', 'GET');
		$resolve = \Sanitizer::get_var('resolve', 'string', 'GET');
		if ($detail)
		{
			ksort($setup_info[$detail]);
			$name = $this->setup->lang($setup_info[$detail]['name']);
			$this->setup_tpl->set_var('description', "<h2>{$name}</h2>\n<ul>\n");
			$header .= $this->setup_tpl->fp('out', 'header');

			$i = 1;
			$details = '';
			foreach ($setup_info[$detail] as $key => $val)
			{
				switch ($key)
				{
						// ignore these ones
					case 'application':
					case 'app_group':
					case 'app_order':
					case 'enable':
					case 'name':
					case 'title':
					case '':
						continue 2; //switch is a looping structure in php - see php.net/continue - skwashd jan08

					case 'tables':
						$tblcnt = count((array)$setup_info[$detail][$key]);
						if (is_array($val))
						{
							$table_names = $this->db->table_names();
							$tables = array();

							$key = '<a href="sqltoarray?appname=' . $detail . '&amp;submit=True">' . $key . '(' . $tblcnt . ')</a>';

							foreach ($val as &$_val)
							{
								if (!in_array($_val, $table_names))
								{
									$_val .= " <b>(missing)</b>";
								}
							}
							$val = implode(',<br>', $val);
						}
						break;
					case 'hooks':
					case 'views':
						$table_names = $this->db->table_names(true);
						$tblcnt = count($setup_info[$detail][$key]);
						if (is_array($val))
						{
							$key =  $key . '(' . $tblcnt . ')';
							foreach ($val as &$_val)
							{
								if ($key == 'views' && !in_array($_val, $table_names))
								{
									$_val .= " <b>(missing)</b>";
								}
							}
							$val = implode(',<br>', $val);
						}
						break;

					case 'depends':
						$val = $this->parsedep($val);
						break;

					case 'hooks':
						if (is_array($val))
						{
							$val = implode(', ', $val);
						}
					case 'author':
					case 'maintainer':
						if (is_array($val))
						{
							$authors = $val;
							$_authors = array();
							foreach ($authors as $author)
							{
								$author_str = $author['name'];
								if (!empty($author['email']))
								{
									$author_str .= " <{$author['email']}>";
								}
								$_authors[] = htmlentities($author_str);
							}
							$val = implode(', ', $_authors);
						}
					default:
						if (is_array($val))
						{
							$val = implode(', ', $val);
						}
				}

				$i = $i % 2;
				$this->setup_tpl->set_var('name', $key);
				$this->setup_tpl->set_var('details', $val);
				$details .= $this->setup_tpl->fp('out', 'detail');
				++$i;
			}
			$this->setup_tpl->set_var('footer_text', "</ul>\n<a href=\"applications?debug={$DEBUG}\">" . $this->setup->lang('Go back') . '</a>');
			$footer = 	$this->setup_tpl->fp('out', 'footer');

			return $header . $details . $footer;

			exit;
		}
		else if ($resolve)
		{
			$version  = \Sanitizer::get_var('version', 'string', 'GET');
			$notables = \Sanitizer::get_var('notables', 'string', 'GET');
			$this->setup_tpl->set_var('description', $this->setup->lang('Problem resolution') . ':');
			$header .= $this->setup_tpl->fp('out', 'header');

			if (\Sanitizer::get_var('post', 'string', 'GET'))
			{
				$header .=  '"' . $setup_info[$resolve]['name'] . '" ' . $this->setup->lang('may be broken') . ' ';
				$header .=  $this->setup->lang('because an application it depends upon was upgraded');
				$header .=  '<br />';
				$header .=  $this->setup->lang('to a version it does not know about') . '.';
				$header .=  '<br />';
				$header .=  $this->setup->lang('However, the application may still work') . '.';
			}
			else if (\Sanitizer::get_var('badinstall', 'string', 'GET'))
			{
				$header .=  '"' . $setup_info[$resolve]['name'] . '" ' . $this->setup->lang('is broken') . ' ';
				$header .=  $this->setup->lang('because of a failed upgrade or install') . '.';
				$header .=  '<br />';
				$header .=  $this->setup->lang('Some or all of its tables are missing') . '.';
				$header .=  '<br />';
				$header .=  $this->setup->lang('You should either uninstall and then reinstall it, or attempt manual repairs') . '.';
			}
			elseif (!$version)
			{
				if ($setup_info[$resolve]['enabled'])
				{
					$header .=  '"' . $setup_info[$resolve]['name'] . '" ' . $this->setup->lang('is broken') . ' ';
				}
				else
				{
					$header .=  '"' . $setup_info[$resolve]['name'] . '" ' . $this->setup->lang('is disabled') . ' ';
				}

				if (!$notables)
				{
					if ($setup_info[$resolve]['status'] == 'D')
					{
						$header .=  $this->setup->lang('because it depends upon') . ':<br />' . "\n";
						list($depapp, $depver) = $this->parsedep($setup_info[$resolve]['depends'], False);
						$depapp_count = count($depapp);
						for ($i = 0; $i < $depapp_count; ++$i)
						{
							$header .=  '<br />' . $depapp[$i] . ': ';
							$list = '';
							foreach ($depver[$i] as $x => $y)
							{
								$list .= $y . ', ';
							}
							$list = substr($list, 0, -2);
							$header .=  "$list\n";
						}
						$header .=  '<br /><br />' . $this->setup->lang('The table definition was correct, and the tables were installed') . '.';
					}
					else
					{
						$header .=  $this->setup->lang('because it was manually disabled') . '.';
					}
				}
				elseif ($setup_info[$resolve]['enable'] == 2)
				{
					$header .=  $this->setup->lang('because it is not a user application, or access is controlled via acl') . '.';
				}
				elseif ($setup_info[$resolve]['enable'] == 0)
				{
					$header .=  $this->setup->lang('because the enable flag for this app is set to 0, or is undefined') . '.';
				}
				else
				{
					$header .=  $this->setup->lang('because it requires manual table installation, <br />or the table definition was incorrect') . ".\n"
						. $this->setup->lang("Please check for sql scripts within the application's directory") . '.';
				}
				$header .=  '<br />' . $this->setup->lang('However, the application is otherwise installed') . '.';
			}
			else
			{
				$header .=  $setup_info[$resolve]['name'] . ' ' . $this->setup->lang('has a version mismatch') . ' ';
				$header .=  $this->setup->lang('because of a failed upgrade, or the database is newer than the installed version of this app') . '.';
				$header .=  '<br />';
				$header .=  $this->setup->lang('If the application has no defined tables, selecting upgrade should remedy the problem') . '.';
				$header .=  '<br />' . $this->setup->lang('However, the application is otherwise installed') . '.';
			}

			$header .=  '<br /><a href="applications?debug=' . $DEBUG . '">' . $this->setup->lang('Go back') . '</a>';
			$footer = $this->setup_tpl->fp('out', 'footer');

			return $header . $footer;

		}
		else if (\Sanitizer::get_var('globals', 'string', 'GET'))
		{
			$this->setup_tpl->set_var('description', '<a href="applications?debug=' . $DEBUG . '">' . $this->setup->lang('Go back') . '</a>');
			$header .= $this->setup_tpl->fp('out', 'header');


			$name = (isset($setup_info[$detail]['title']) ? $setup_info[$detail]['title'] : $this->setup->lang($setup_info[$detail]['name']));
			$this->setup_tpl->set_var('name', $this->setup->lang('application'));
			$this->setup_tpl->set_var('details', $name);
			$this->setup_tpl->set_var('bg_color', 'th');
			$detail = $this->setup_tpl->fp('out', 'detail');

			$this->setup_tpl->set_var('bg_color', 'row_on');
			$this->setup_tpl->set_var('details', $this->setup->lang('register_globals_' . $_GET['globals']));
			$detail .= $this->setup_tpl->fp('out', 'detail');
			$footer = $this->setup_tpl->pparse('out', 'footer');
			//response
			return $header . $detail . $footer;

			exit;
		}
		else
		{


			$this->setup_tpl->set_var('description', $this->setup->lang('Select the desired action(s) from the available choices'));
			$header .= $this->setup_tpl->fp('out', 'header');

			$this->setup_tpl->set_var('appdata', $this->setup->lang('Application Data'));
			$this->setup_tpl->set_var('actions', $this->setup->lang('Actions'));
			$this->setup_tpl->set_var('action_url', '../applications');
			$this->setup_tpl->set_var('app_info', $this->setup->lang('Application Name'));
			$this->setup_tpl->set_var('app_status', $this->setup->lang('Application Status'));
			$this->setup_tpl->set_var('app_currentver', $this->setup->lang('Current Version'));
			$this->setup_tpl->set_var('app_version', $this->setup->lang('Available Version'));
			$this->setup_tpl->set_var('app_install', $this->setup->lang('Install'));
			$this->setup_tpl->set_var('app_remove', $this->setup->lang('Remove'));
			$this->setup_tpl->set_var('app_upgrade', $this->setup->lang('Upgrade'));
			$this->setup_tpl->set_var('app_resolve', $this->setup->lang('Resolve'));
			$this->setup_tpl->set_var('check', 'stock_form-checkbox.png');
			$this->setup_tpl->set_var('install_all', $this->setup->lang('Install All'));
			$this->setup_tpl->set_var('upgrade_all', $this->setup->lang('Upgrade All'));
			$this->setup_tpl->set_var('remove_all', $this->setup->lang('Remove All'));
			$this->setup_tpl->set_var('lang_debug', $this->setup->lang('enable debug messages'));
			$this->setup_tpl->set_var('debug', '<input type="checkbox" name="debug" value="True"' . ($DEBUG ? ' checked' : '') . '>');

			$header .= $this->setup_tpl->fp('out', 'app_header');
			$apps = '';

			$i = 0;
			foreach ($setup_info as $key => $value)
			{
				if (isset($value['name']) && $value['name'] != 'phpgwapi' && $value['name'] != 'notifywindow')
				{
					++$i;
					$row = $i % 2 ? 'off' : 'on';
					//		\_debug_array($value['name']);
					$value['title'] = !isset($value['title']) || !strlen($value['title']) ? str_replace('*', '', $this->setup->lang($value['name'])) : $value['title'];
					$this->setup_tpl->set_var('apptitle', $value['title']);
					$this->setup_tpl->set_var('currentver', isset($value['currentver']) ? $value['currentver'] : '');
					$this->setup_tpl->set_var('version', $value['version']);
					$this->setup_tpl->set_var('bg_class',  "row_{$row}");
					$this->setup_tpl->set_var('row_remove', '');

					switch ($value['status'])
					{
						case 'C':
							$this->setup_tpl->set_var('row_remove', "row_remove_{$row}");
							$this->setup_tpl->set_var('remove', '<input type="checkbox" name="remove[' . $value['name'] . ']" />');
							$this->setup_tpl->set_var('upgrade', '&nbsp;');
							if (!$this->detection->check_app_tables($value['name']))
							{
								// App installed and enabled, but some tables are missing
								$this->setup_tpl->set_var('instimg', 'stock_database.png');
								$this->setup_tpl->set_var('bg_class', "row_err_table_{$row}");
								$this->setup_tpl->set_var('instalt', $this->setup->lang('Not Completed'));
								$this->setup_tpl->set_var('resolution', '<a href="applications?resolve=' . $value['name'] . '&amp;badinstall=True">' . $this->setup->lang('Potential Problem') . '</a>');
								$status = $this->setup->lang('Requires reinstall or manual repair') . ' - ' . $value['status'];
							}
							else
							{
								$this->setup_tpl->set_var('instimg', 'stock_yes.png');
								$this->setup_tpl->set_var('instalt', $this->setup->lang('%1 status - %2', $value['title'], $this->setup->lang('Completed')));
								$this->setup_tpl->set_var('install', '&nbsp;');
								if ($value['enabled'])
								{
									$this->setup_tpl->set_var('resolution', '');
									$status = "[{$value['status']}] " . $this->setup->lang('OK');
								}
								else
								{
									$notables = '';
									if (
										isset($value['tables'][0])
										&& $value['tables'][0] != ''
									)
									{
										$notables = '&amp;notables=True';
									}
									$this->setup_tpl->set_var('bg_class', "row_err_gen_{$row}");
									$this->setup_tpl->set_var(
										'resolution',
										'<a href="applications?resolve=' . $value['name'] .  $notables . '">' . $this->setup->lang('Possible Reasons') . '</a>'
									);
									$status = "[{$value['status']}] " . $this->setup->lang('Disabled');
								}
							}
							break;
						case 'U':
							$this->setup_tpl->set_var('instimg', 'package-generic.png');
							$this->setup_tpl->set_var('instalt', $this->setup->lang('Not Completed'));
							if (!isset($value['currentver']) || !$value['currentver'])
							{
								$this->setup_tpl->set_var('bg_class', "row_install_{$row}");
								$status = "[{$value['status']}] " . $this->setup->lang('Please install');
								if (isset($value['tables']) && is_array($value['tables']) && $value['tables'] && $this->detection->check_app_tables($value['name'], True))
								{
									// Some tables missing
									$this->setup_tpl->set_var('bg_class', "row_err_gen_{$row}");
									$this->setup_tpl->set_var('instimg', 'stock_database.png');
									$this->setup_tpl->set_var('row_remove', 'row_remove_' . ($i ? 'off' : 'on'));
									$this->setup_tpl->set_var('remove', '<input type="checkbox" name="remove[' . $value['name'] . ']" />');
									$this->setup_tpl->set_var('resolution', '<a href="applications?resolve=' . $value['name'] . '&amp;badinstall=True">' . $this->setup->lang('Potential Problem') . '</a>');
									$status = "[{$value['status']}] " . $this->setup->lang('Requires reinstall or manual repair');
								}
								else
								{
									$this->setup_tpl->set_var('remove', '&nbsp;');
									$this->setup_tpl->set_var('resolution', '');
									$status = "[{$value['status']}] " . $this->setup->lang('Available to install');
								}
								$this->setup_tpl->set_var('install', '<input type="checkbox" name="install[' . $value['name'] . ']" />');
								$this->setup_tpl->set_var('upgrade', '&nbsp;');
							}
							else
							{
								$this->setup_tpl->set_var('bg_class', "row_upgrade_{$row}");
								$this->setup_tpl->set_var('install', '&nbsp;');
								// TODO display some info about breakage if you mess with this app
								$this->setup_tpl->set_var('upgrade', '<input type="checkbox" name="upgrade[' . $value['name'] . ']">');
								$this->setup_tpl->set_var('row_remove', 'row_remove_' . ($i ? 'off' : 'on'));
								$this->setup_tpl->set_var('remove', '<input type="checkbox" name="remove[' . $value['name'] . ']">');
								$this->setup_tpl->set_var('resolution', '');
								$status = "[{$value['status']}] " . $this->setup->lang('Requires upgrade');
							}
							break;
						case 'V':
							$this->setup_tpl->set_var('instimg', 'package-generic.png');
							$this->setup_tpl->set_var('instalt', $this->setup->lang('Not Completed'));
							$this->setup_tpl->set_var('install', '&nbsp;');
							$this->setup_tpl->set_var('row_remove', 'row_remove_' . ($i ? 'off' : 'on'));
							$this->setup_tpl->set_var('remove', '<input type="checkbox" name="remove[' . $value['name'] . ']">');
							$this->setup_tpl->set_var('upgrade', '<input type="checkbox" name="upgrade[' . $value['name'] . ']">');
							$this->setup_tpl->set_var('resolution', '<a href="applications?resolve=' . $value['name'] . '&amp;version=True">' . $this->setup->lang('Possible Solutions') . '</a>');
							$status = "[{$value['status']}] " . $this->setup->lang('Version Mismatch');
							break;
						case 'D':
							$this->setup_tpl->set_var('bg_class', "row_err_gen_{$row}");
							$depstring = $this->parsedep($value['depends']);
							$this->setup_tpl->set_var('instimg', 'stock_no.png');
							$this->setup_tpl->set_var('instalt', $this->setup->lang('Dependency Failure'));
							$this->setup_tpl->set_var('install', '&nbsp;');
							$this->setup_tpl->set_var('remove', '&nbsp;');
							$this->setup_tpl->set_var('upgrade', '&nbsp;');
							$this->setup_tpl->set_var('resolution', '<a href="applications?resolve=' . $value['name'] . '">' . $this->setup->lang('Possible Solutions') . '</a>');
							$status = "[{$value['status']}] " . $this->setup->lang('Dependency Failure') . $depstring;
							break;
						case 'P':
							$this->setup_tpl->set_var('bg_class', "row_err_gen_{$row}");
							$depstring = $this->parsedep($value['depends']);
							$this->setup_tpl->set_var('instimg', 'stock_no.png');
							$this->setup_tpl->set_var('instalt', $this->setup->lang('Post-install Dependency Failure'));
							$this->setup_tpl->set_var('install', '&nbsp;');
							$this->setup_tpl->set_var('remove', '&nbsp;');
							$this->setup_tpl->set_var('upgrade', '&nbsp;');
							$this->setup_tpl->set_var('resolution', '<a href="applications?resolve=' . $value['name'] . '&post=True">' . $this->setup->lang('Possible Solutions') . '</a>');
							$status = "[{$value['status']}] " . $this->setup->lang('Post-install Dependency Failure') . $depstring;
							break;
						default:
							$this->setup_tpl->set_var('instimg', 'package-generic.png');
							$this->setup_tpl->set_var('instalt', $this->setup->lang('Not Completed'));
							$this->setup_tpl->set_var('install', '&nbsp;');
							$this->setup_tpl->set_var('remove', '&nbsp;');
							$this->setup_tpl->set_var('upgrade', '&nbsp;');
							$this->setup_tpl->set_var('resolution', '');
							$status = '';
							break;
					}
					$this->setup_tpl->set_var('appinfo', $status);
					$this->setup_tpl->set_var('appname', $value['name']);

					$apps .= $this->setup_tpl->fp('out', 'apps');
				}
			}
		}

		$this->setup_tpl->set_var('submit', $this->setup->lang('Save'));
		$this->setup_tpl->set_var('cancel', $this->setup->lang('Cancel'));
		$footer = $this->setup_tpl->fp('out', 'app_footer');
		$footer .= $this->setup_tpl->fp('out', 'footer');
		$footer .= $this->html->get_footer();

		return $header . $apps . $footer;
	}
}
