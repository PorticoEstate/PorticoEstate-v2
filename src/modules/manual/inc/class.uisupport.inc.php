<?php

/**
 * Support - ask for support
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2010 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package Frontend
 * @version $Id$
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

/**
 * Manual
 *
 * @package Manual
 */
class manual_uisupport
{

	public $public_functions = array(
		'send' => true,
	);
	private $serverSettings, $userSettings;

	public function __construct()
	{
		Settings::getInstance()->update('flags', ['xslt_app' => true, 'noframework' => true]);
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');

	}

	public function send()
	{
		$values		 = Sanitizer::get_var('values');
		$form_type	 = Sanitizer::get_var('form_type', 'string', 'GET', 'aligned');

		$support_address = isset($config->config_data['support_address']) && $config->config_data['support_address'] ? $config->config_data['support_address'] : $this->serverSettings['support_address'];

		$receipt = array();
		if (isset($values['save']))
		{
			if (phpgw::is_repost())
			{
				$receipt['error'][] = array('msg' => lang('repost'));
			}

			if (!isset($values['details']) || !$values['details'])
			{
				$receipt['error'][] = array('msg' => lang('Please give som details'));
			}

			$attachments = array();

			if (isset($_FILES['file']['name']) && $_FILES['file']['name'])
			{
				$file_name = str_replace(' ', '_', $_FILES['file']['name']);
				$mime_magic = createObject('phpgwapi.mime_magic');
				$mime = $mime_magic->filename2mime($file_name);

				$attachments[] = array(
					'file' => $_FILES['file']['tmp_name'],
					'name' => $file_name,
					'type' => $mime
				);
			}

			if (!$receipt['error'])
			{
				if (isset($this->serverSettings['smtp_server']) && $this->serverSettings['smtp_server'])
				{

					$send = CreateObject('phpgwapi.send');

					$from = "{$this->userSettings['fullname']}<{$values['from_address']}>";
					$toarray = explode(',', trim(str_replace(';', ',', $support_address)));
					//trim each email address
					$toarray = array_map('trim', $toarray);
					$to		 = implode(';', $toarray);

					$receive_notification = true;
					$rcpt = $send->msg('email', $to, 'Support', stripslashes(nl2br($values['details'])), '', '', '', $from, $this->userSettings['fullname'], 'html', '', $attachments, $receive_notification);

					if ($rcpt)
					{
						$receipt['message'][] = array('msg' => lang('message sent'));
					}
				}
				else
				{
					$receipt['error'][] = array('msg' => lang('SMTP server is not set! (admin section)'));
				}
			}
		}

		//optional support address per app
		$app = Sanitizer::get_var('app');
		$config = CreateObject('phpgwapi.config', $app);
		$config->read();

		$phpgwapi_common = new \phpgwapi_common();
		$data = array(
			'msgbox_data'		 => $phpgwapi_common->msgbox($phpgwapi_common->msgbox_data($receipt)),
			'from_name'			 => $this->userSettings['fullname'],
			'from_address'		 => $this->userSettings['preferences']['common']['email'],
			'form_action'		 => phpgw::link('/index.php', array('menuaction' => 'manual.uisupport.send', 'form_type' => $form_type)),
			'support_address'	 => $support_address,
			'form_type'			 => $form_type
		);

		phpgwapi_xslttemplates::getInstance()->add_file('support');
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('send' => $data));
	}
}
