<?php

/**
 * phpGroupWare - controller: a part of a Facilities Management System.
 *
 * @author Erink Holm-Larsen <erik.holm-larsen@bouvet.no>
 * @author Torstein Vadla <torstein.vadla@bouvet.no>
 * @copyright Copyright (C) 2011,2012 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @package property
 * @subpackage controller
 * @version $Id$
 */

use App\modules\phpgwapi\controllers\Accounts\Accounts;

phpgw::import_class('property.solocation');

class location_finder
{

	private $so, $accounts_obj;

	public function __construct()
	{
		$this->so = CreateObject('property.solocation');
		$this->accounts_obj = new Accounts();
	}

	function get_responsibilities($data = array())
	{
		if (empty($data['bypass_responsibility']))
		{
			$data['filter_role_on_contact'] = $this->accounts_obj->get($data['user_id'])->person_id;
		}
		$locations = $this->so->read($data);

		$total_records = $this->so->total_records;

		return $locations;
	}

	function get_buildings_on_property($parent_location_code)
	{

		$children = execMethod('property.solocation.get_children', $parent_location_code);

		foreach ($children as &$entry)
		{
			$entry['id'] = "{$parent_location_code}-{$entry['id']}";
		}

		return $children;
	}

	function get_building_location_code($location_code)
	{
		if (strlen($location_code) == 6)
		{
			$location_code_arr = explode('-', $location_code, 2);
			$building_location_code = $location_code_arr[0];
		}
		else if (strlen($location_code) > 6)
		{
			$location_code_arr = explode('-', $location_code, 3);
			$building_location_code = $location_code_arr[0] . "-" . $location_code_arr[1];
		}
		else
		{
			$building_location_code = $location_code;
		}

		return $building_location_code;
	}

	function get_location_level($location_code)
	{
		$level = $location_code ? count(explode('-', $location_code)) : 0;

		return $level;
	}
}
