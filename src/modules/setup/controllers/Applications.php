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
use App\modules\phpgwapi\services\Twig;

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
	private $twig;

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
		$this->twig = Twig::getInstance();

		$flags = array(
			'noheader' 		=> True,
			'nonavbar'		=> True,
			'currentapp'	=> 'setup',
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
/*
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
		*/
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
		$setup_info = $this->detection->get_db_versions($setup_info);
		$setup_info = $this->detection->compare_versions($setup_info);
		$setup_info = $this->detection->check_depends($setup_info);
		ksort($setup_info);

		$db_config = $this->db->get_config();
		$header = '';

		// Common template variables
		$templateVars = [
			'check' => 'stock_form-checkbox.png',
			'install_all' => $this->setup->lang('Install All'),
			'upgrade_all' => $this->setup->lang('Upgrade All'),
			'remove_all' => $this->setup->lang('Remove All'),
			'debug' => $DEBUG,
			'app_info' => $this->setup->lang('Application Name'),
			'app_status' => $this->setup->lang('Application Status'),
			'app_currentver' => $this->setup->lang('Current Version'),
			'app_version' => $this->setup->lang('Available Version'),
			'app_install' => $this->setup->lang('Install'),
			'app_remove' => $this->setup->lang('Remove'),
			'app_upgrade' => $this->setup->lang('Upgrade'),
			'app_resolve' => $this->setup->lang('Resolve'),
			'submit' => $this->setup->lang('Save'),
			'cancel' => $this->setup->lang('Cancel')
		];

		if (\Sanitizer::get_var('submit', 'string', 'POST'))
		{
			$header .= $this->html->get_header($this->setup->lang('Application Management'), False, 'config', $this->db->get_domain() . '(' . $db_config['db_type'] . ')');
			
			// Use renderBlock for the header part
			$headerVars = [
				'description' => $this->setup->lang('App install/remove/upgrade') . ':'
			];
			$header .= $this->twig->renderBlock('applications.html.twig', 'header', $headerVars);

			$appname = \Sanitizer::get_var('appname', 'string', 'POST');
			$remove  = \Sanitizer::get_var('remove', 'string', 'POST');
			$install = \Sanitizer::get_var('install', 'string', 'POST');
			$upgrade = \Sanitizer::get_var('upgrade', 'string', 'POST');

			if (!isset($this->process->oProc) || !$this->process->oProc)
			{
				$this->process->init_process();
			}

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

			// Process installs
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

							// Default values have to be processed - even for apps without tables - after register for locations::add to work
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

			// Process upgrades
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
					}
					else
					{
						$header .=  '<li>' . $this->setup->lang('%1 upgraded', $this->setup->lang($appname)) . ".</li>";
					}

					$header .=  "<li>To upgrade languages - run <b>'Manage Languages'</b> from setup</li>\n</ul>\n";
				}
			}

			$header .=  "<h3><a href=\"applications?debug={$DEBUG}\">" . $this->setup->lang('Done') . "</h3>\n";
			
			// Render the footer using Twig
			$footer = $this->twig->renderBlock('applications.html.twig', 'footer', ['footer_text' => '']);

			return $header . $footer;
		}
		else
		{
			$header .= $this->html->get_header($this->setup->lang('Application Management'), False, 'config', $this->db->get_domain() . '(' . $db_config['db_type'] . ')');
		}

		$detail = \Sanitizer::get_var('detail', 'string', 'GET');
		$resolve = \Sanitizer::get_var('resolve', 'string', 'GET');
		
		// Handle application detail view
		if ($detail)
		{
			ksort($setup_info[$detail]);
			$name = $this->setup->lang($setup_info[$detail]['name']);
			
			// Use renderBlock for the header part with detail-specific description
			$headerVars = [
				'description' => "<h2>{$name}</h2>\n<ul>\n"
			];
			$header .= $this->twig->renderBlock('applications.html.twig', 'header', $headerVars);

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
						continue 2;

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

				// Use renderBlock for each detail row
				$detailVars = [
					'name' => $key,
					'details' => $val
				];
				$details .= $this->twig->renderBlock('applications.html.twig', 'detail', $detailVars);
			}
			
			// Render the footer with "Go back" link
			$footerVars = [
				'footer_text' => "</ul>\n<a href=\"applications?debug={$DEBUG}\">" . $this->setup->lang('Go back') . '</a>'
			];
			$footer = $this->twig->renderBlock('applications.html.twig', 'footer', $footerVars);

			return $header . $details . $footer;
		}
		// Handle problem resolution view
		else if ($resolve)
		{
			$version  = \Sanitizer::get_var('version', 'string', 'GET');
			$notables = \Sanitizer::get_var('notables', 'string', 'GET');
			
			// Use renderBlock for the header part with resolve-specific description
			$headerVars = [
				'description' => $this->setup->lang('Problem resolution') . ':'
			];
			$header .= $this->twig->renderBlock('applications.html.twig', 'header', $headerVars);

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
			
			// Render the footer
			$footer = $this->twig->renderBlock('applications.html.twig', 'footer', []);

			return $header . $footer;
		}
		else if (\Sanitizer::get_var('globals', 'string', 'GET'))
		{
			// Use renderBlock for the header part with a "Go back" link
			$headerVars = [
				'description' => '<a href="applications?debug=' . $DEBUG . '">' . $this->setup->lang('Go back') . '</a>'
			];
			$header .= $this->twig->renderBlock('applications.html.twig', 'header', $headerVars);

			// Use renderBlock for detail entries
			$detailVars1 = [
				'name' => $this->setup->lang('application'),
				'details' => $name
			];
			$detail = $this->twig->renderBlock('applications.html.twig', 'detail', $detailVars1);

			$detailVars2 = [
				'details' => $this->setup->lang('register_globals_' . $_GET['globals'])
			];
			$detail .= $this->twig->renderBlock('applications.html.twig', 'detail', $detailVars2);
			
			$footer = $this->twig->renderBlock('applications.html.twig', 'footer', []);
			
			return $header . $detail . $footer;
		}
		else
		{
			// Main application list view
			
			// Use renderBlock for the header part
			$headerVars = [
				'description' => $this->setup->lang('Select the desired action(s) from the available choices')
			];
			$header .= $this->twig->renderBlock('applications.html.twig', 'header', $headerVars);
			
			// Use renderBlock for the app_header part
			$header .= $this->twig->renderBlock('applications.html.twig', 'app_header', $templateVars);
			
			$apps = '';
			$i = 0;
			
			// Generate the app rows
			foreach ($setup_info as $key => $value)
			{
				if (isset($value['name']) && $value['name'] != 'phpgwapi' && $value['name'] != 'notifywindow')
				{
					++$i;
					$row = $i % 2 ? 'off' : 'on';
					$value['title'] = !isset($value['title']) || !strlen($value['title']) ? str_replace('*', '', $this->setup->lang($value['name'])) : $value['title'];
					
					// Prepare app row data for Twig template
					$appVars = [
						'bg_class' => "row_{$row}",
						'appname' => $value['name'],
						'currentver' => isset($value['currentver']) ? $value['currentver'] : '',
						'version' => $value['version'],
						'row_remove' => '',
						'row_install' => '',
						'row_upgrade' => '',
						'install' => '&nbsp;',
						'upgrade' => '&nbsp;',
						'remove' => '&nbsp;',
						'resolution' => '&nbsp;'
					];

					switch ($value['status'])
					{
						case 'C':
							$appVars['row_remove'] = "row_remove_{$row}";
							$appVars['remove'] = '<input type="checkbox" name="remove[' . $value['name'] . ']" />';
							$appVars['upgrade'] = '&nbsp;';
							
							if (!$this->detection->check_app_tables($value['name']))
							{
								// App installed and enabled, but some tables are missing
								$appVars['instimg'] = 'stock_database.png';
								$appVars['bg_class'] = "row_err_table_{$row}";
								$appVars['instalt'] = $this->setup->lang('Not Completed');
								$appVars['resolution'] = '<a href="applications?resolve=' . $value['name'] . '&amp;badinstall=True">' . $this->setup->lang('Potential Problem') . '</a>';
								$appVars['appinfo'] = $this->setup->lang('Requires reinstall or manual repair') . ' - ' . $value['status'];
								}
							else
							{
								$appVars['instimg'] = 'stock_yes.png';
								$appVars['instalt'] = $this->setup->lang('%1 status - %2', $value['title'], $this->setup->lang('Completed'));
								$appVars['install'] = '&nbsp;';
								
								if ($value['enabled'])
								{
									$appVars['resolution'] = '';
									$appVars['appinfo'] = "[{$value['status']}] " . $this->setup->lang('OK');
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
									$appVars['bg_class'] = "row_err_gen_{$row}";
									$appVars['resolution'] = '<a href="applications?resolve=' . $value['name'] . $notables . '">' . $this->setup->lang('Possible Reasons') . '</a>';
									$appVars['appinfo'] = "[{$value['status']}] " . $this->setup->lang('Disabled');
								}
							}
							break;
						case 'U':
							$appVars['instimg'] = 'package-generic.png';
							$appVars['instalt'] = $this->setup->lang('Not Completed');
							
							if (!isset($value['currentver']) || !$value['currentver'])
							{
								$appVars['bg_class'] = "row_install_{$row}";
								$appVars['appinfo'] = "[{$value['status']}] " . $this->setup->lang('Please install');
								
								if (isset($value['tables']) && is_array($value['tables']) && $value['tables'] && $this->detection->check_app_tables($value['name'], True))
								{
									// Some tables missing
									$appVars['bg_class'] = "row_err_gen_{$row}";
									$appVars['instimg'] = 'stock_database.png';
									$appVars['row_remove'] = 'row_remove_' . ($i % 2 ? 'off' : 'on');
									$appVars['remove'] = '<input type="checkbox" name="remove[' . $value['name'] . ']" />';
									$appVars['resolution'] = '<a href="applications?resolve=' . $value['name'] . '&amp;badinstall=True">' . $this->setup->lang('Potential Problem') . '</a>';
									$appVars['appinfo'] = "[{$value['status']}] " . $this->setup->lang('Requires reinstall or manual repair');
								}
								else
								{
									$appVars['remove'] = '&nbsp;';
									$appVars['resolution'] = '';
									$appVars['appinfo'] = "[{$value['status']}] " . $this->setup->lang('Available to install');
								}
								$appVars['install'] = '<input type="checkbox" name="install[' . $value['name'] . ']" />';
								$appVars['upgrade'] = '&nbsp;';
							}
							else
							{
								$appVars['bg_class'] = "row_upgrade_{$row}";
								$appVars['install'] = '&nbsp;';
								$appVars['upgrade'] = '<input type="checkbox" name="upgrade[' . $value['name'] . ']">';
								$appVars['row_remove'] = 'row_remove_' . ($i % 2 ? 'off' : 'on');
								$appVars['remove'] = '<input type="checkbox" name="remove[' . $value['name'] . ']">';
								$appVars['resolution'] = '';
								$appVars['appinfo'] = "[{$value['status']}] " . $this->setup->lang('Requires upgrade');
							}
							break;
						case 'V':
							$appVars['instimg'] = 'package-generic.png';
							$appVars['instalt'] = $this->setup->lang('Not Completed');
							$appVars['install'] = '&nbsp;';
							$appVars['row_remove'] = 'row_remove_' . ($i % 2 ? 'off' : 'on');
							$appVars['remove'] = '<input type="checkbox" name="remove[' . $value['name'] . ']">';
							$appVars['upgrade'] = '<input type="checkbox" name="upgrade[' . $value['name'] . ']">';
							$appVars['resolution'] = '<a href="applications?resolve=' . $value['name'] . '&amp;version=True">' . $this->setup->lang('Possible Solutions') . '</a>';
							$appVars['appinfo'] = "[{$value['status']}] " . $this->setup->lang('Version Mismatch');
							break;
						case 'D':
							$appVars['bg_class'] = "row_err_gen_{$row}";
							$depstring = $this->parsedep($value['depends']);
							$appVars['instimg'] = 'stock_no.png';
							$appVars['instalt'] = $this->setup->lang('Dependency Failure');
							$appVars['install'] = '&nbsp;';
							$appVars['remove'] = '&nbsp;';
							$appVars['upgrade'] = '&nbsp;';
							$appVars['resolution'] = '<a href="applications?resolve=' . $value['name'] . '">' . $this->setup->lang('Possible Solutions') . '</a>';
							$appVars['appinfo'] = "[{$value['status']}] " . $this->setup->lang('Dependency Failure') . $depstring;
							break;
						case 'P':
							$appVars['bg_class'] = "row_err_gen_{$row}";
							$depstring = $this->parsedep($value['depends']);
							$appVars['instimg'] = 'stock_no.png';
							$appVars['instalt'] = $this->setup->lang('Post-install Dependency Failure');
							$appVars['install'] = '&nbsp;';
							$appVars['remove'] = '&nbsp;';
							$appVars['upgrade'] = '&nbsp;';
							$appVars['resolution'] = '<a href="applications?resolve=' . $value['name'] . '&post=True">' . $this->setup->lang('Possible Solutions') . '</a>';
							$appVars['appinfo'] = "[{$value['status']}] " . $this->setup->lang('Post-install Dependency Failure') . $depstring;
							break;
						default:
							$appVars['instimg'] = 'package-generic.png';
							$appVars['instalt'] = $this->setup->lang('Not Completed');
							$appVars['install'] = '&nbsp;';
							$appVars['remove'] = '&nbsp;';
							$appVars['upgrade'] = '&nbsp;';
							$appVars['resolution'] = '';
							$appVars['appinfo'] = '';
							break;
					}
					
					// Render the app row with Twig
					$apps .= $this->twig->renderBlock('applications.html.twig', 'apps', $appVars);
				}
			}
		}
		
		// Render the footer parts
		$app_footer = $this->twig->renderBlock('applications.html.twig', 'app_footer', $templateVars);
		$footer = $this->twig->renderBlock('applications.html.twig', 'footer', []);
		$footer .= $this->html->get_footer();

		return $header . $apps . $app_footer . $footer;
	}
}
