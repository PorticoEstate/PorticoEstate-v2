<?php

/**
 * phpsysinfo - Setup
 *
 * @copyright Copyright (C) 2000-2002,2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package phpsysinfo
 * @subpackage setup
 * @version $Id: tables_update.inc.php 4732 2010-02-04 13:16:56Z sigurd $
 */

use App\modules\phpgwapi\services\Settings;

/**
 * Update from 1.7 to 3.0
 *
 * @return string New version number
 */

$test[] = '1.7';
function phpsysinfo_upgrade1_7()
{
	$GLOBALS['setup_info']['phpsysinfo']['currentver'] = '3.0';
	return $GLOBALS['setup_info']['phpsysinfo']['currentver'];
}

/**
 * Update from 3.0 to 3.0.4
 *
 * @return string New version number
 */

$test[] = '3.0';
function phpsysinfo_upgrade3_0()
{
	$GLOBALS['setup_info']['phpsysinfo']['currentver'] = '3.0.4';
	return $GLOBALS['setup_info']['phpsysinfo']['currentver'];
}

/**
 * Update from 3.0.4 to 3.1.7
 *
 * @return string New version number
 */

$test[] = '3.0.4';
function phpsysinfo_upgrade3_0_4()
{
	$GLOBALS['setup_info']['phpsysinfo']['currentver'] = '3.1.7';
	return $GLOBALS['setup_info']['phpsysinfo']['currentver'];
}

/**
 * Update from 3.1.7 to 3.2.8
 *
 * @return string New version number
 */

$test[] = '3.1.7';
function phpsysinfo_upgrade3_1_7()
{
	$GLOBALS['setup_info']['phpsysinfo']['currentver'] = '3.2.8';
	return $GLOBALS['setup_info']['phpsysinfo']['currentver'];
}


/**
 * Update from 3.2.8 to 3.3.2
 *
 * @return string New version number
 */
$test[] = '3.2.8';
function phpsysinfo_upgrade3_2_8()
{
	$GLOBALS['setup_info']['phpsysinfo']['currentver'] = '3.3.2';
	return $GLOBALS['setup_info']['phpsysinfo']['currentver'];
}
/**
 * Update from 3.3.2 to 3.4.3
 *
 * @return string New version number
 */
$test[] = '3.3.2';
function phpsysinfo_upgrade3_3_2()
{
	$currentver = '3.4.3';
	Settings::getInstance()->update('setup_info', ['phpsysinfo' => ['currentver' => $currentver]]);
	return $currentver;
}
