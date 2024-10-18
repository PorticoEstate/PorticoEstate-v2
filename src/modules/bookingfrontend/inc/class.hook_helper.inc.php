<?php

/**
 * Bookingfrontend - Hook helper
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2013 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package Property
 * @version $Id: class.hook_helper.inc.php 14728 2016-02-11 22:28:46Z sigurdne $
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

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
use App\modules\bookingfrontend\helpers\UserHelper;


/**
 * Hook helper
 *
 * @package bookingfrontend
 */
class bookingfrontend_hook_helper
{

	private $perform_action = false;

	public function __construct()
	{
		$script_path = Sanitizer::get_var('REDIRECT_URL', 'string', 'SERVER');

		if ($script_path && preg_match('/bookingfrontend/', $script_path))
		{
			$this->perform_action = true;
		}
	}

	public function set_cookie_domain()
	{
		if (!$this->perform_action)
		{
			return;
		}
		//get from local config
		$config = CreateObject('phpgwapi.config', 'bookingfrontend');
		$config->read();

		$serverSettings = Settings::getInstance()->get('server');


		$serverSettings['cookie_domain'] = !empty($serverSettings['cookie_domain']) ? $serverSettings['cookie_domain'] : '';

		if (!empty($config->config_data['cookie_domain']))
		{
			$serverSettings['cookie_domain'] = $config->config_data['cookie_domain'];
		}
		$serverSettings['usecookies'] = $config->config_data['usecookies'];
	}

	public function after_navbar()
	{
		if (!$this->perform_action)
		{
			return;
		}

		$orgs = array();

		$bouser = new UserHelper();

		if ($bouser->is_logged_in())
		{
			$orgs = Cache::session_get($bouser->get_module(), $bouser::ORGARRAY_SESSION_KEY);
		}

		if (!empty($orgs) && is_array($orgs) && count($orgs) > 1)
		{
			$org_id = $bouser->org_id;
			$orgnr = $bouser->orgnr;
		}
		else
		{
			return;
		}

		$lang_none = lang('none');
		$org_option = "";
		foreach ($orgs as $org)
		{
			$selected = '';
			if ($org_id == (int)$org['org_id'])
			{
				$selected = ' selected="selected"';
			}

			$org_option .= <<<HTML

				<option value='{$org['org_id']}'{$selected}>{$org['orgname']}</option>
HTML;
		}

		if ($orgs)
		{
			$base = 'bookingfrontend/';
			if (isset($_GET) && $_GET)
			{
				$oArgs = json_encode($_GET);
			}
			else
			{
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
	<script>
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
}
