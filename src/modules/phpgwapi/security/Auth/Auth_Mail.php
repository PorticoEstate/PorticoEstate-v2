<?php
	/**
	* Authentication based on Mail server
	* @author Dan Kuykendall <seek3r@phpgroupware.org>
	* @copyright Copyright (C) 2000-2008 Free Software Foundation, Inc. http://www.fsf.org/
	* @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
	* @package phpgwapi
	* @subpackage accounts
	* @version $Id$
	*/

/*
	   This program is free software: you can redistribute it and/or modify
	   it under the terms of the GNU Lesser General Public License as published by
	   the Free Software Foundation, either version 2 of the License, or
	   (at your option) any later version.

	   This program is distributed in the hope that it will be useful,
	   but WITHOUT ANY WARRANTY; without even the implied warranty of
	   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	   GNU General Public License for more details.

	   You should have received a copy of the GNU Lesser General Public License
	   along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

	namespace App\modules\phpgwapi\security\Auth;

	/**
	* Authentication based on Mail server
	*
	* @package phpgwapi
	* @subpackage accounts
	*/
	class Auth extends Auth_
	{
		/**
		* @var string $ssl_args arguments used for SSL connection - disables SSL validation by default
		* @internal see http://php.net/imap_open for more info
		*/
		private $ssl_args = '/novalidate-cert';
		
		public function __construct()
		{
			parent::__construct();
		}
		
		function authenticate($username, $passwd)
		{
			$server = $this->serverSettings['mail_server'];

			switch ( $this->serverSettings['mail_login_type'] )
			{
				case 'vmailmgr':
					$username = "{$username}@{$this->serverSettings['mail_suffix']}";
					break;
				case 'ispman':
					$username = "{$username}_" . preg_replace('/\./', '_', $this->serverSettings['mail_suffix']);
					break;
			}

			$extra = '';
			switch ( $this->serverSettings['mail_server_type'] )
			{
				case 'pop3s':
 					$port = 995;
					$extra = "/ssl{$this->ssl_args}";
				case 'pop3':
					$extra = "/pop3{$extra}";
					$port = 110;
					break;
				case 'imaps':
					$port = 993;
					$extra = "/ssl{$this->ssl_args}";
	 				$mailauth = imap_open("\{{$this->serverSettings['mail_server']}:{$port}\}INBOX", $username , $passwd);
					break;
				case 'imap':
				default:
					$port = 143;
					$this->serverSettings['mail_port'] = '143';
			}

	 		return !! @imap_open("\{{$server}{$extra}:{$port}\}INBOX", $username , $passwd);
		}

		function change_password($old_passwd, $new_passwd, $account_id = 0)
		{
			return '';
		}
	}
