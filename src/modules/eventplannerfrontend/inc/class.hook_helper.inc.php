<?php

/**
 * eventplanner - Hook helper
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2017 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package eventplanner
 * @version $Id: class.hook_helper.inc.php 14726 2016-02-11 20:07:07Z sigurdne $
 */
/*
	  This program is free software: you can redistribute it and/or modify
	  it under the terms of the GNU General Public License as published by
	  the Free Software Foundation, either version 2 of the License, or
	  (at your option) any later version.

	  This program is distributed in the hope that it will be useful,
	  but WITHOUT ANY WARRANTY; without even the implied warranty of
	  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	  GNU General Public License for more details.

	  You should have received a copy of the GNU General Public License
	  along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

use App\modules\phpgwapi\services\Settings;
use App\Database\Db;

/**
 * Hook helper
 *
 * @package property
 */
class eventplannerfrontend_hook_helper
{

	private $perform_action = false;

	public function __construct()
	{
		/**
		 * Wait for it...
		 */
		//parent::__construct();

		$script_path = Sanitizer::get_var('REDIRECT_URL', 'string', 'SERVER');

		if ($script_path && preg_match('/eventplannerfrontend/', $script_path))
		{
			$this->perform_action = true;
		}
	}
	/**
	 * set auth_type for custom login - called from login
	 *
	 * @return void
	 */
	public function set_auth_type()
	{
		if (!$this->perform_action)
		{
			return;
		}
		//get from local config
		$config = CreateObject('phpgwapi.config', 'eventplannerfrontend')->read();

		if (!empty($config['auth_type']))
		{
			Settings::getInstance()->update('server', ['auth_type' => $config['auth_type']]);
		}
	}

	public function set_cookie_domain()
	{
		if (!$this->perform_action)
		{
			return;
		}

		//get from local config
		$config = CreateObject('phpgwapi.config', 'eventplannerfrontend')->read();


		if (!empty($config->config_data['cookie_domain']))
		{
			Settings::getInstance()->update('server', ['cookie_domain' => $config->config_data['cookie_domain']]);
		}
		Settings::getInstance()->update('server', ['usecookies' => $config->config_data['usecookies']]);
	}

	public function login()
	{
		if (!$this->perform_action)
		{
			return;
		}
		$bouser = CreateObject('eventplannerfrontend.bouser');
		$bouser->log_in();
	}

	/**
	 * Show info for homepage
	 *
	 * @return void
	 */
	public function home()
	{
		if (!$this->perform_action)
		{
			return;
		}

		phpgw::import_class('phpgwapi.uicommon');

		$data = array(
			'config' => CreateObject('phpgwapi.config', 'eventplannerfrontend')->read(),
		);
		phpgwapi_uicommon::render_template_xsl(array('home'), array('view' => $data));
	}

	public function after_navbar()
	{
		if (!$this->perform_action)
		{
			return;
		}

		$orgs = array();
		if (!empty($_SESSION['orgs']) && is_array($_SESSION['orgs']))
		{
			$orgs = Sanitizer::get_var('orgs', 'string', 'SESSION');
		}

		$session_org_id = Sanitizer::get_var('session_org_id', 'string', 'GET');

		function get_ids_from_array($org)
		{
			return $org['id'];
		}

		if ($session_org_id && in_array($session_org_id, array_map("get_ids_from_array", $orgs)))
		{
			try
			{
				$_SESSION['org_id'] = createObject('booking.sfValidatorNorwegianOrganizationNumber')->clean($session_org_id);
			}
			catch (sfValidatorError $e)
			{
				$_SESSION['org_id'] = -1;
			}
		}
		else if ($_GET['session_org_id'])
		{
			$_SESSION['org_id'] = -1;
		}

		/**
		 * $_SESSION['orgs'] is set in eventplannerfrontend_external_user::get_user_org_id()
		 */

		if (!empty($_SESSION['orgs']) && is_array($_SESSION['orgs']))
		{
			$org_id = Sanitizer::get_var('org_id', 'int', 'SESSION');
		}
		else
		{
			return;
		}

		$lang_none = lang('none');
		$org_option = "<option value='-1'>{$lang_none}</option>";
		foreach ($orgs as $org)
		{
			$selected = '';
			if ($org_id == (int)$org['id'])
			{
				$selected = ' selected="selected"';
			}

			$org_option .= <<<HTML

				<option value='{$org['id']}'{$selected}>{$org['name']}</option>

HTML;
		}

		if ($orgs)
		{
			if (!empty($_GET['menuaction']))
			{
				$action = phpgw::link(
					'/eventplannerfrontend/',
					array(
						'menuaction' => Sanitizer::get_var('menuaction')
					)
				);
				$base = 'eventplannerfrontend/';
				$oArgs = '{menuaction:"' . Sanitizer::get_var('menuaction') . '"}';
			}
			else
			{
				$action = phpgw::link('/eventplannerfrontend/home/');
				$base = 'eventplannerfrontend/home/';
				$oArgs = '{}';
			}

			$message = 'Velg organisasjon';

			$org_select = <<<HTML

					<label for="session_org_id">Velg Organisasjon:</label>
					<select name="session_org_id" id="session_org_id">
						{$org_option}
					</select>

HTML;
		}

		$html = <<<HTML

			<div id="organsation_select">
				$org_select
			</div>
HTML;


		echo $html;


		$js = <<<JS
	<script type="text/javascript">
		$(document).ready(function ()
		{

			$("#session_org_id").change(function ()
			{
				var session_org_id = $(this).val();
				var oArgs = {$oArgs};
				oArgs.session_org_id = session_org_id;
				var requestUrl = phpGWLink('{$base}', oArgs);
				window.open(requestUrl, "_self");
			});
		});
	</script>
JS;
		echo $js;
	}

	/**
	 * hook to add account
	 *
	 * this function is a wrapper function for eventplanner
	 * duplicated in registration?
	 *
	 * @param _hookValues contains the hook values as array
	 * @returns nothing
	 */
	function addaccount()
	{
		$flags = Settings::getInstance()->get('flags');

		if ($flags['currentapp'] !== 'registration')
		{
			return;
		}

		$hook_values = Settings::getInstance()->get('hook_values');

		$account_id = (int)$hook_values['account_id'];
		$db = Db::getInstance();

		$db->query("SELECT account_id FROM phpgw_accounts_data WHERE account_id = {$account_id}", __LINE__, __FILE__);
		if ($db->next_record())
		{
			return;
		}

		if (!empty($_SERVER['HTTP_UID']))
		{
			$ssn = (string)$_SERVER['HTTP_UID'];
		}
		else
		{
			$ssn = (string)$_SERVER['OIDC_pid'];
		}

		if (!$ssn)
		{
			return;
		}

		$ssn_hash = "{SHA}" . base64_encode(phpgwapi_common::hex2bin(sha1($ssn)));

		$hash_safe = $db->db_addslashes($ssn_hash); // just to be safe :)

		$data = json_encode(array('ssn_hash' => $hash_safe));

		$sql = "INSERT INTO phpgw_accounts_data (account_id, account_data) VALUES ({$account_id}, '{$data}')";
		$db->query($sql, __LINE__, __FILE__);
	}
}
