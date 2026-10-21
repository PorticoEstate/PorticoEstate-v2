<?php

/**
 * EMail - UI Class for Attaching Files
 *
 * @author Angelo (Angles) Puglisi <angles@aminvestments.com>
 * @copyright Copyright (C) 2002 Angelo Tony Puglisi (Angles)
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package email
 * @version $Id$
 * @internal Based on AngleMail http://www.anglemail.org/
 */

/**
 * UI Class for Attaching Files
 *
 * @package email
 */
class uiattach_file
{
	var $public_functions = array(
		'attach'	=> True
		//'show_ui'	=> True
	);
	var $tpl;
	var $bo;

	var $debug = 0;

	function __construct()
	{
		//return;
	}

	function attach()
	{
		//return;

		$phpgw_flags = array(
			'currentapp' => 'email',
			'enable_network_class' => True,
			'noheader'   => True,
			'nonavbar'   => True
		);

		$GLOBALS['phpgw_info']['flags'] = $phpgw_flags;

		$GLOBALS['phpgw']->template->set_file(
			array(
				'T_attach_file' => 'attach_file.tpl',
				'T_attach_file_blocks' => 'attach_file_blocks.tpl'
			)
		);
		$GLOBALS['phpgw']->template->set_block('T_attach_file_blocks', 'B_alert_msg', 'V_alert_msg');
		$GLOBALS['phpgw']->template->set_block('T_attach_file_blocks', 'B_attached_list', 'V_attached_list');
		$GLOBALS['phpgw']->template->set_block('T_attach_file_blocks', 'B_attached_none', 'V_attached_none');
		$GLOBALS['phpgw']->template->set_block('T_attach_file_blocks', 'B_delete_btn', 'V_delete_btn');

		// create boattach_file object
		$this->bo = CreateObject('email.boattach_file');
		// tell it we want it to fill the global template we establisted above
		// DO NOT USE AMPERSAND because we declare the param as a reference when we made the function 
		$this->bo->set_ref_var_holder($GLOBALS['phpgw']->template);
		// now run the code
		$this->bo->attach();

		// ... the boattach_file class all the work ...

		// output the HTML
		$GLOBALS['phpgw']->template->pfp('out', 'T_attach_file');

		//$GLOBALS['phpgw']->common->phpgw_exit();
		if (\App\modules\email\services\MailMessage::hasInstance())
		{
			// close down ALL mailserver streams
			\App\modules\email\services\MailMessage::getInstance()->end_request();
			// destroy the object
			\App\modules\email\services\MailMessage::releaseInstance();
		}

		// shut down this transaction
		$GLOBALS['phpgw']->common->phpgw_exit(False);
	}
}
