<?php

/**
 * phpGroupWare EMail - http://phpGroupWare.org
 *
 * @author Angles <angles@phpgroupware.org>
 * @copyright Copyright (C) 2001-2004 Angelo Tony Puglisi
 * @copyright Portions Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package email
 * @subpackage setup
 * @version $Id$
 * @internal Based on AeroMail by Mark Cushman <mark@cushman.net>
 */

$setup_info['email']['name']      = 'email';
$setup_info['email']['version']   = '0.9.17.501';
$setup_info['email']['app_order'] = '2';
$setup_info['email']['enable']    = 1;
$setup_info['email']['app_group']	= 'office';

$setup_info['email']['tables']    = array('phpgw_anglemail', 'phpgw_email_message_index');

$setup_info['email']['author'][] = array('name' => '&quot;Angles&quot; Angelo Tony Puglisi');
$setup_info['email']['license']  = 'GPL';
$setup_info['email']['description'] =
	'phpGroupWare Email reader with multiple accounts and mailbox filtering.';
$setup_info['email']['globals_checked'] = True;

$setup_info['email']['maintainer'][] = array(
	'name'  => 'Sigurd Nes',
	'email' => 'sigurdne at gmail.com'
);


/* The hooks this app includes, needed for hooks registration */
$setup_info['email']['hooks'][] = 'email_add_def_prefs';
$setup_info['email']['hooks'][] = 'home';
$setup_info['email']['hooks'][] = 'login';
//	$setup_info['email']['hooks'][] = 'manual';
$setup_info['email']['hooks'][] = 'notifywindow';
$setup_info['email']['hooks'][] = 'notifywindow_simple';
$setup_info['email']['hooks'][] = 'add_def_prefs';
//$setup_info['email']['hooks']['getFolderContent'] = 'email.email_service.getFolderContent';
$setup_info['email']['hooks']['menu'] = 'email.service.get_menu';

/* Dependacies for this app to work */
$setup_info['email']['depends'][] = array(
	'appname' => 'phpgwapi',
	'versions' => array('0.9.16', '0.9.17', '0.9.18')
);

$setup_info['email']['depends'][] = array(
	'appname' => 'admin',
	'versions' => array('0.9.16', '0.9.17', '0.9.18')
);

$setup_info['email']['depends'][] = array(
	'appname' => 'emailadmin',
	'versions' => array('1.5.005')
);

$setup_info['email']['depends'][] = array(
	'appname' => 'preferences',
	'versions' => array('0.9.16', '0.9.17', '0.9.18')
);
