<?php

/**
 * Administration - Configuration hook
 *
 * @author Mark Peters <skeeter@phpgroupware.org>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package admin
 * @subpackage hooks
 * @version $Id$
 */



/**
 * Get HTML checkbox with groups that are candidates for the field finnish date at tts
 *
 * @param $config
 * @return string HTML checkboxes to be placed in a table
 */
function required_group($config)
{
	$accounts = new \App\modules\phpgwapi\controllers\Accounts\Accounts();
	$groups			 = $accounts->get_list('groups', -1, '', '', '', -1, array('active' => 1));
	$default_group_lid = !empty($config['default_group_lid']) ? $config['default_group_lid'] : '';
	$out	 = '<option value="">' . lang('none') . '</option>' . "\n";

	foreach ($groups as $group)
	{
		$selected = '';
		if ($group->lid == $default_group_lid)
		{
			$selected = ' selected = "selected"';
		}

		$out .= <<<HTML
			<option value='{$group->lid}'{$selected}>{$group->lid}</option>
HTML;
	}
	return $out;
}
