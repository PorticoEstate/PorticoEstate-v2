<?php

/**
 * Todo - setup
 *
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @copyright Copyright (C) 2000-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package todo
 * @subpackage setup
 * @version $Id$
 */

$setup_info['todo']['name']		= 'todo';
$setup_info['todo']['version']		= '1.0.0.000';
$setup_info['todo']['app_order']	= 9;
$setup_info['todo']['tables']		= array('phpgw_todo');
$setup_info['todo']['enable']		= 1;
$setup_info['todo']['app_group']	= 'office';

$setup_info['todo']['description'] = 'Basic ToDo list';

$setup_info['todo']['author'][] = array(
	'name'	=> 'Joseph Engo',
	'email'	=> 'jengo@phpgroupware.org'
);

$setup_info['todo']['author'][] = array(
	'name'	=> 'Mark Logemann'
);

$setup_info['todo']['author'][] = array(
	'name'	=> 'Bettina Gille',
	'email'	=> 'ceb@phpgroupware.org'
);

$setup_info['todo']['author'][] = array(
	'name'	=> 'Sigurd Nes',
	'email'	=> 'sigurdne@gmail.com'
);

$setup_info['todo']['maintainer'][] = array(
	'name'	=> 'Sigurd Nes',
	'email'	=> 'sigurdne@gmail.com'
);

/* The hooks this app includes, needed for hooks registration */
$setup_info['todo']['hooks'] = array(
	'home',
	'deleteaccount',
	'manual',
	'settings',
	'menu'	=> 'todo.menu.get_menu'
);

/* Dependencies for this app to work */
$setup_info['todo']['depends'][] = array(
	'appname' => 'phpgwapi',
	'versions' => array('0.9.17', '0.9.18')
);

$setup_info['todo']['depends'][] = array(
	'appname' => 'admin',
	'versions' => array('0.9.17', '0.9.18')
);

$setup_info['todo']['depends'][] = array(
	'appname' => 'preferences',
	'versions' => array('0.9.17', '0.9.18')
);
