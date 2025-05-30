<?php

/**
 * phpGroupWare - HRM: a  human resource competence management system.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package hrm
 * @subpackage core
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;


/**
 * Description
 * @package hrm
 */

class hrm_bocommon
{
	var $start;
	var $query;
	var $filter;
	var $sort;
	var $order;
	var $cat_id;
	var $district_id;


	var $public_functions = array(
		'select_part_of_town'	=> true,
		'menu'	=> true,
	);

	var $socommon, $account, $dateformat, $datetimeformat, $serverSettings, $userSettings;

	function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');

		$this->socommon			= CreateObject('hrm.socommon');
		$this->account		= $this->userSettings['account_id'];

		switch ($this->serverSettings['db_type'])
		{
			case 'mssqlnative':
			case 'mssql':
				$this->dateformat 		= "M d Y";
				$this->datetimeformat 	= "M d Y g:iA";
				break;
			case 'mysql':
				$this->dateformat 		= "Y-m-d";
				$this->datetimeformat 	= "Y-m-d G:i:s";
				break;
			case 'pgsql':
				$this->dateformat 		= "Y-m-d";
				$this->datetimeformat 	= "Y-m-d G:i:s";
				break;
		}
	}

	//FIXME Remove the need for this - use the jscal class which now supports xslt
	function jscalendar()
	{
	}

	function check_perms($rights, $required)
	{
		return ($rights & $required);
	}

	/**
	 *
	 * @param integer $owner_id
	 * @param array $grants
	 * @param integer $required
	 * @return bool
	 */
	function check_perms2($owner_id, $grants,  $required)
	{
		if (isset($grants['accounts'][$owner_id]) && ($grants['accounts'][$owner_id] & $required))
		{
			return true;
		}
		$accounts_obj = new Accounts();
		$equalto = $accounts_obj->membership($owner_id);
		foreach ($grants['groups'] as $group => $_right)
		{
			if (isset($equalto[$group]) && ($_right & $required))
			{
				return true;
			}
		}

		return false;
	}

	function create_preferences($app = '', $user_id = '')
	{
		return $this->socommon->create_preferences($app, $user_id);
	}

	function msgbox_data($receipt)
	{
		$msgbox_data_error = array();
		if (isset($receipt['error']) and is_array($receipt['error']))
		{
			foreach ($receipt['error'] as $errors)
			{
				$msgbox_data_error += array($errors['msg'] => false);
			}
		}

		$msgbox_data_message = array();

		if (isset($receipt['message']) and is_array($receipt['message']))
		{
			foreach ($receipt['message'] as $messages)
			{
				$msgbox_data_message += array($messages['msg'] => true);
			}
		}

		$msgbox_data = $msgbox_data_error + $msgbox_data_message;

		return $msgbox_data;
	}

	function moneyformat($amount)
	{
		if ($this->serverSettings['db_type'] == 'mssql')
		{
			$moneyformat	= "CONVERT(MONEY," . "'$amount'" . ",0)";
		}
		else
		{
			$moneyformat	= "'" . $amount . "'";
		}

		return $moneyformat;
	}

	function date_array($datestr)
	{
		$dateformat = $this->userSettings['preferences']['common']['dateformat'];

		$fields = preg_split('/[.\/-]/', $datestr);
		foreach (preg_split('/[.\/-]/', $dateformat) as $n => $field)
		{
			$date[$field] = intval($fields[$n]);

			if ($field == 'M')
			{
				for ($i = 1; $i <= 12; $i++)
				{
					if (date('M', mktime(0, 0, 0, $i, 1, 2000)) == $fields[$n])
					{
						$date['m'] = $i;
					}
				}
			}
		}

		$ret = array(
			'year'  => $date['Y'],
			'month' => $date['m'],
			'day'   => $date['d']
		);
		return $ret;
	}

	function date_to_timestamp($date = '')
	{
		if (!$date)
		{
			return false;
		}

		$date_array	= $this->date_array($date);
		$date	= mktime(8, 0, 0, $date_array['month'], $date_array['day'], $date_array['year']);

		return $date;
	}

	function select_list($selected = '', $input_list = '')
	{
		if (isset($input_list) and is_array($input_list))
		{
			foreach ($input_list as $entry)
			{
				$sel_entry = '';
				if ($entry['id'] == $selected)
				{
					$sel_entry = 'selected';
				}
				$entry_list[] = array(
					'id'		=> $entry['id'],
					'name'		=> $entry['name'],
					'selected'	=> $sel_entry
				);
			}
			for ($i = 0; $i < count($entry_list); $i++)
			{
				if ($entry_list[$i]['selected'] != 'selected')
				{
					unset($entry_list[$i]['selected']);
				}
			}
		}
		return $entry_list;
	}

	function no_access($links = '')
	{
		phpgwapi_xslttemplates::getInstance()->add_file(array('no_access', 'menu'));

		$receipt['error'][] = array('msg' => lang('NO ACCESS'));

		$msgbox_data = $this->msgbox_data($receipt);
		$phpgwapi_common = new \phpgwapi_common();

		$data = array(
			'msgbox_data'	=> $phpgwapi_common->msgbox($msgbox_data),
			'links'		=> $links,
		);

		$appname	= lang('No access');

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . $appname]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('no_access' => $data));
	}
}
