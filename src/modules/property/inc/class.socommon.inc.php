<?php
	/**
	 * phpGroupWare - property: a Facilities Management System.
	 *
	 * @author Sigurd Nes <sigurdne@online.no>
	 * @copyright Copyright (C) 2003,2004,2005,2006,2007 Free Software Foundation, Inc. http://www.fsf.org/
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
	 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
	 * @package property
	 * @subpackage core
	 * @version $Id$
	 */
	/**
	 * Description
	 * @package property
	 */

	use App\Database\Db;
	use \App\Database\Db2;
	use App\modules\property\helpers\CommonDataHelper;
	use App\modules\phpgwapi\security\Acl;

	use App\modules\phpgwapi\services\Settings;

	phpgw::import_class('phpgwapi.datetime');

	class property_socommon
	{

		/**
		 * @var string $join the sql syntax to use for JOIN
		 */
		var $join = ' INNER JOIN ';

		/**
		 * @var string $like the sql syntax to use for a case insensitive LIKE
		 */
		var $like = 'LIKE';
		var $db, $account, $left_join, $userSettings;
		protected $commonDataHelper;

		function __construct()
		{
			$this->db = Db::getInstance();
			$this->userSettings = Settings::getInstance()->get('user');

			$this->account	= isset($this->userSettings['account_id']) ? (int)$this->userSettings['account_id'] : -1;

			$serverSettings = Settings::getInstance()->get('server');

			switch ($serverSettings['db_type'])
			{
				case 'pgsql':
					$this->join	 = " JOIN ";
					$this->like	 = "ILIKE";
					break;
				case 'postgres':
					$this->join	 = " JOIN ";
					$this->like	 = "ILIKE";
					break;
				default:
				//do nothing for now
			}

			$this->left_join = " LEFT JOIN ";
			$this->commonDataHelper = new CommonDataHelper($this->db, $this->join);
		}

		function fm_cache( $name = '', $value = '' )
		{
			return $this->commonDataHelper->fmCache($name, $value);
		}

		/**
		 * Clear all content from cache
		 *
		 */
		function reset_fm_cache()
		{
			$this->commonDataHelper->resetFmCache();
		}

		/**
		 * Clear computed userlist for location and rights from cache
		 *
		 * @return integer number of values was found and cleared
		 */
		function reset_fm_cache_userlist()
		{
			return $this->commonDataHelper->resetFmCacheUserlist($this->like);
		}

		/**
		 * unquote (stripslashes) recursivly the whole array
		 *
		 * @param $arr array to unquote (var-param!)
		 */
		public function unquote( &$arr )
		{
			$this->commonDataHelper->unquote($arr);
		}

		function create_preferences( $app = '', $user_id = '' )
		{
			return $this->commonDataHelper->createPreferences($app, $user_id);
		}

		function read_single_tenant( $id )
		{
			return $this->commonDataHelper->readSingleTenant($id);
		}

		function check_location( $location_code = '', $type_id = '' )
		{
			return $this->commonDataHelper->checkLocation($location_code, $type_id);
		}

		function select_part_of_town( $district_id = 0 )
		{
			return $this->commonDataHelper->selectPartOfTown($district_id);
		}

		function select_district_list()
		{
			return $this->commonDataHelper->selectDistrictList();
		}

		/**
		 * Finds the next ID for a record at a table
		 *
		 * @param string $table tablename in question
		 * @param array $key conditions
		 * @return int the next id
		 */
		function next_id( $table = '', $key = '' )
		{
			return $this->commonDataHelper->nextId($table, $key);
		}

		function get_lookup_entity( $location )
		{
			return $this->commonDataHelper->getLookupEntity($location);
		}

		function get_start_entity( $location )
		{
			return $this->commonDataHelper->getStartEntity($location);
		}

		function increment_id( $name )
		{
			return $this->commonDataHelper->incrementId($name);
		}

		function new_db( $db = null )
		{
			return $this->commonDataHelper->newDb($db);
		}

		function get_max_location_level()
		{
			return $this->commonDataHelper->getMaxLocationLevel();
		}

		/**
		 * Get list of accessible physical locations for current user
		 *
		 * @param integer $required Right the user has to be granted at location
		 *
		 * @return array $access_location list of accessible physical locations
		 */
		public function get_location_list( $required )
		{
			return $this->commonDataHelper->getLocationList($required);
		}

		/**
		 * 
		 * @param int $id
		 * @return array
		 */
		public function get_order_type( $id )
		{
			return $this->commonDataHelper->getOrderType($id);
		}
	}