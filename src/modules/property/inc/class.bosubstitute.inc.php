<?php

	/**
	 * phpGroupWare - registration
	 *
	 * @author Sigurd Nes <sigurdne@online.no>
	 * @copyright Copyright (C) 2018 Free Software Foundation, Inc. http://www.fsf.org/
	 * This file is part of phpGroupWare.
	 *
	 * phpGroupWare is free software; you can redistribute it and/or modify
	 * it under the terms of the GNU General Public License as published by
	 * the Free Software Foundation; either version 2 of the License, or
	 * (at your option) any later version.
	 *
	 * phpGroupWare is distributed in the hope that it will be useful,
	 * but WITHOUT ANY WARRANTY; without even the implied warranty of
	 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 * GNU General Public License for more details.
	 *
	 * You should have received a copy of the GNU General Public License
	 * along with phpGroupWare; if not, write to the Free Software
	 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
	 *
	 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
	 * @internal Development of this application was funded by http://www.bergen.kommune.no/
	 * @package registration
	 * @version $Id: class.bodimb_role_user.inc.php 16604 2017-04-20 14:53:00Z sigurdne $
	 */

	use App\modules\phpgwapi\services\Settings;
	use App\modules\phpgwapi\controllers\Accounts\Accounts;

	class property_bosubstitute
	{

		var $public_functions = array(
		);

		var $so,$account_id,$userSettings,$phpgwapi_common,$accounts_obj;

		function __construct()
		{
			$this->userSettings = Settings::getInstance()->get('user');
			$this->phpgwapi_common = new \phpgwapi_common();
			$this->accounts_obj = new Accounts();

			$this->account_id	 = $this->userSettings['account_id'];
			$this->so			 = CreateObject('property.sosubstitute');
		}

		public function read( $data )
		{
			static $users	 = array();
			$values			 = $this->so->read($data);

			foreach ($values as &$entry)
			{
				if ($entry['user_id'])
				{
					if (!$entry['user'] = $users[$entry['user_id']])
					{
						$entry['user']				 = $this->accounts_obj->get($entry['user_id'])->__toString();
						$users[$entry['user_id']]	 = $entry['user'];
					}
				}
				if ($entry['substitute_user_id'])
				{
					if (!$entry['substitute'] = $users[$entry['substitute_user_id']])
					{
						$entry['substitute']				 = $this->accounts_obj->get($entry['substitute_user_id'])->__toString();
						$users[$entry['substitute_user_id']] = $entry['substitute'];
					}
				}
			}

			return $values;
		}

		public function delete( $data )
		{
			return $this->so->delete($data);
		}

		public function update_substitute( $user_id, $substitute_user_id, $start_time )
		{
			return $this->so->update_substitute($user_id, $substitute_user_id, $start_time);
		}

		public function get_substitute( $user_id )
		{
			return $this->so->get_substitute($user_id);
		}

		public function get_substitute_list( $user_id )
		{
			return $this->so->get_substitute_list($user_id);
		}
	}