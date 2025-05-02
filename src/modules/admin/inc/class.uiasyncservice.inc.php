<?php

/**************************************************************************\
 * phpGroupWare Admin - Timed Asynchron Services for phpGroupWare           *
 * Written by Ralf Becker <RalfBecker@outdoor-training.de>                  *
 * Class to admin cron-job like timed calls of phpGroupWare methods         *
 * -------------------------------------------------------------------------*
 * This library is part of the phpGroupWare API                             *
 * http://www.phpgroupware.org/                                             *
 * ------------------------------------------------------------------------ *
 * This library is free software; you can redistribute it and/or modify it  *
 * under the terms of the GNU Lesser General Public License as published by *
 * the Free Software Foundation; either version 2.1 of the License,         *
 * or any later version.                                                    *
 * This library is distributed in the hope that it will be useful, but      *
 * WITHOUT ANY WARRANTY; without even the implied warranty of               *
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.                     *
 * See the GNU Lesser General Public License for more details.              *
 * You should have received a copy of the GNU Lesser General Public License *
 * along with this library; if not, write to the Free Software Foundation,  *
 * Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA            *
	\**************************************************************************/

/* $Id$ */

use App\modules\phpgwapi\services\AsyncService;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\security\Acl;

class admin_uiasyncservice
{
	public $public_functions = array('index' => True);
	private $flags;
	private $userSettings;
	private $serverSettings;
	private $sessions;
	private $phpgwapi_common;
	private $accounts;
	private $acl;


	public function __construct()
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->sessions = Sessions::getInstance();
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->accounts = new Accounts();
		$this->acl = Acl::getInstance();

		$this->flags['app_header'] = lang('Admin') . ' - ' . lang('Asynchronous timed services');
		Settings::getInstance()->update('flags', [
			'menu_selection' => 'admin::admin::async',
			'app_header' => lang('Admin') . ' - ' . lang('Asynchronous timed services')
		]);
	}

	public function index()
	{
		if ($this->acl->check('asyncservice_access', 1, 'admin'))
		{
			phpgw::redirect_link('/index.php');
		}
		$this->phpgwapi_common->phpgw_header(true);

		$manual_run = Sanitizer::get_var('manual_run', 'bool', 'POST');

		$async = AsyncService::getInstance();

		$async->debug = Sanitizer::get_var('debug', 'bool', 'POST');

		$units = array(
			'year'  => lang('Year'),
			'month' => lang('Month'),
			'day'   => lang('Day'),
			'dow'   => lang('Day of week<br>(0-6, 0=Sun)'),
			'hour'  => lang('Hour<br>(0-23)'),
			'min'   => lang('Minute')
		);

		$debug = Sanitizer::get_var('debug', 'bool', 'POST');

		$send		= Sanitizer::get_var('send', 'bool', 'POST');
		$test		= Sanitizer::get_var('test', 'bool', 'POST');
		$cancel		= Sanitizer::get_var('cancel', 'bool', 'POST');
		$install	= Sanitizer::get_var('install', 'bool', 'POST');
		$update		= Sanitizer::get_var('update', 'bool', 'POST');
		$asyncservice = Sanitizer::get_var('asyncservice', 'string', 'POST');

		if ($send || $test || $cancel || $install || $update || $asyncservice || $manual_run)
		{

			if ($manual_run)
			{
				$account_id = $this->userSettings['account_id'];
				$num = $async->check_run('crontab');
				echo "<p><b>{$num} jobs found</b></p>\n";
				//reset your environment
				$this->sessions->set_account_id($account_id);
				$this->sessions->read_repositories(False, False);
				$this->userSettings  = $this->sessions->get_user();
				Settings::getInstance()->set('user', $this->userSettings);
			}

			$times = array();
			foreach (array_keys($units) as $u)
			{
				$times[$u] = Sanitizer::get_var($u, 'string', 'POST');
				if ($times[$u] === '')
				{
					unset($times[$u]);
				}
			}

			if ($test)
			{
				$email = Sanitizer::get_var('email', 'string', 'POST');
				if (!$email)
				{
					$email = isset($this->userSettings['preferences']['common']['email']) ? $this->userSettings['preferences']['common']['email'] : '';
				}
				$validator = CreateObject('phpgwapi.EmailAddressValidator');
				if (!$validator->check_email_address($email))
				{
					echo '<p><b>' . lang("Not a not valid email address") . "</b></p>\n";
				}
				else if (!$async->set_timer($times, 'test', 'admin.uiasyncservice.test', array('to' => $email)))
				{
					echo '<p><b>' . lang("Error setting timer, wrong syntax or maybe there's one already running !!!") . "</b></p>\n";
				}
				unset($prefs);
			}
			if ($cancel)
			{
				if (!$async->cancel_timer('test'))
				{
					echo '<p><b>' . lang("Error canceling timer, maybe there's none set !!!") . "</b></p>\n";
				}
			}
			if ($install)
			{
				if (!($install = $async->install($times)))
				{
					echo '<p><b>' . lang('Error: %1 not found or other error !!!', $async->crontab) . "</b></p>\n";
				}
				else
				{
					$asyncservice = 'cron';
				}
			}

			if ($asyncservice)
			{
				if (
					!isset($this->serverSettings['asyncservice'])
					|| $asyncservice != $this->serverSettings['asyncservice']
				)
				{
					//$config = CreateObject('phpgwapi.config','phpgwapi');
					$config = new \App\modules\phpgwapi\services\Config('phpgwapi');
					$config->read();
					$config->value('asyncservice', $asyncservice);
					$config->save_repository();
					unset($config);
					$this->serverSettings['asyncservice'] = $asyncservice;
					Settings::getInstance()->set('server', $this->serverSettings);
				}

				if (($asyncservice == 'off' || $asyncservice == 'fallback') && !$install)
				{
					$async->uninstall();
				}
			}
		}
		else
		{
			$times = array('min' => '*/5');		// set some default
		}
		echo '<form action="' . phpgw::link('/index.php', array('menuaction' => 'admin.uiasyncservice.index')) . '" method="POST">' . "\n<p>";
		echo '<div style="text-align: left; margin: 10px;">' . "\n";

		$last_run = $async->last_check_run();
		$lr_date = $last_run['end'] ? $this->phpgwapi_common->show_date($last_run['end']) : lang('never');
		echo '<p><b>' . lang('Async services last executed') . '</b>: ' . $lr_date . ' (' . $last_run['run_by'] . ")</p>\n<hr>\n";

		if (!$async->only_fallback)
		{
			$installed = $async->installed();
			if (is_array($installed) && isset($installed['cronline']))
			{
				$async_use['cron'] = lang('crontab only (recomended)');
			}
		}
		$async_use['fallback']    = lang('fallback (after each pageview)');
		$async_use['off'] = lang('disabled (not recomended)');

		$_config_asyncservice = $this->serverSettings['asyncservice'] == 'cron' && ! isset($async_use['cron']) ? 'off' :  $this->serverSettings['asyncservice'];

		echo '<p><b>' . lang('Run Asynchronous services') . '</b>' .
			' <select name="asyncservice" onChange="this.form.submit();">';

		foreach ($async_use as $key => $label)
		{
			$selected = $key == $_config_asyncservice ? ' selected' : '';
			echo "<option value=\"$key\"$selected>$label</option>\n";
		}
		echo "</select></p>\n";

		if ($async->only_fallback)
		{
			echo '<p>' . lang('Under windows you can only use the fallback mode at the moment. Fallback means the jobs get only checked after each page-view !!!') . "</p>\n";
		}
		else
		{
			echo '<p>' . lang('Installed crontab') . ": \n";

			if (is_array($installed) && isset($installed['cronline']))
			{
				echo "$installed[cronline]</p>";
			}
			elseif ($installed === 0)
			{
				echo '<b>' . lang('%1 not found or not executable !!!', $async->crontab) . "</b></p>\n";
			}
			else
			{
				echo '<b>' . lang('asyncservices not yet installed or other error (%1) !!!', $installed['error']) . "</b></p>\n";
			}
			echo '<p><input type="submit" name="install" value="' . lang('Install crontab') . "\">\n" .
				lang("for the times below (empty values count as '*', all empty = every minute)") . "</p>\n";
		}

		echo "<hr><table border=0><tr>\n";
		foreach ($units as $u => $ulabel)
		{
			echo " <td>$ulabel</td><td><input name=\"$u\" value=\"$times[$u]\" size=5> &nbsp; </td>\n";
		}
		echo "</tr><tr>\n <td colspan=4>\n";
		echo ' <input type="submit" name="send" value="' . lang('Calculate next run') . '"></td>' . "\n";
		echo ' <td colspan="8"><input type="checkbox" name="debug" value="1"' . ($debug ? ' checked' : '') . "> \n" .
			lang('Enable debug-messages') . "</td>\n</tr></table>\n";

		if ($send)
		{
			$next = $async->next_run($times, True);

			echo "<p>asyncservice::next_run(";
			print_r($times);
			echo ")=" . ($next === False ? 'False' : "'$next'=" . $this->phpgwapi_common->show_date($next)) . "</p>\n";
		}
		echo '<hr><p><input type="submit" name="cancel" value="' . lang('Cancel TestJob!') . "\"> &nbsp;\n";
		echo lang('email') . '<input type="text" name="email" value="">' . "\n";
		echo '<input type="submit" name="test" value="' . lang('Start TestJob!') . "\">\n";
		echo lang('for the times above') . "</p>\n";
		echo '<p>' . lang('The TestJob sends you a mail everytime it is called.') . "</p>\n";

		echo '<hr><p><b>' . lang('Jobs') . ":</b>\n";
		if ($jobs = $async->read('%'))
		{
			echo "<table  class=\"pure-table  pure-table-bordered\" border=1>\n<tr>\n<th>Id</th><th>" . lang('Next run') . '</th><th>' . lang('Times') . '</th><th>' . lang('Method') . '</th><th>' . lang('Data') . "</th><th>" . lang('LoginID') . "</th></tr>\n";
			foreach ($jobs as $job)
			{
				echo "<tr>\n<td>{$job['id']}</td><td>" . $this->phpgwapi_common->show_date($job['next']) . "</td><td>";
				print_r($job['times']);
				echo "</td><td>{$job['method']}</td><td>";
				print_r($job['data']);
				echo "</td><td align=\"center\">" . $this->accounts->id2name($job['account_id']) . "</td></tr>\n";
			}
			echo "</table>\n";
		}
		else
		{
			echo lang('No jobs in the database !!!') . "</p>\n";
		}
		echo '<p><input type="submit" name="update" value="' . lang('Update') . '">' . "\n";
		echo '<input type="submit" name="manual_run" value="' . lang('manual run') . '"></p>' . "\n";
		echo "</form>\n";
	}

	public function test($data)
	{
		$to = $data['to'];
		$from = isset($this->userSettings['preferences']['common']['email']) ? $this->userSettings['preferences']['common']['email'] : '';

		if (!CreateObject('phpgwapi.EmailAddressValidator')->check_email_address($from))
		{
			$from = "Noreply@notdefined.org";
		}
		$send = new \App\modules\phpgwapi\services\Send();
		$returncode = $send->msg('email', $to, $subject = 'Asynchronous timed services', 'Greatings from cron ;-)', '', '', '', $from);

		if (!$returncode)	// not nice, but better than failing silently
		{
			echo "<p>uiasynservice::test: sending message to '$to' subject='$subject' failed !!!<br>\n";
			echo $send->err['desc'] . "</p>\n";
		}
	}
}
