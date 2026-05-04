<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2008 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package phpgroupware
 * @subpackage property
 * @category core
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

use App\modules\phpgwapi\services\InterLink as InterLinkService;

/**
 * interlink - handles information of relations of items across locations.
 *
 * @package phpgroupware
 * @subpackage property
 * @category core
 */
class property_interlink
{

	/**
	 * @var object InterLinkService
	 */
	var  $interLinkService;

	/**
	 * Constructor
	 *
	 */
	function __construct()
	{
		$this->interLinkService = new InterLinkService();
	}

	/**
	 * Get relation of the interlink
	 *
	 * @param string  $appname  the application name for the location
	 * @param string  $location the location name
	 * @param integer $id       id of the referenced item
	 * @param integer $role     role of the referenced item ('origin' or 'target')
	 *
	 * @return array interlink data
	 */
	public function get_relation($appname, $location, $id, $role = 'origin')
	{
		return $this->interLinkService->get_relation($appname, $location, $id, $role);
	}

	/**
	 * Get specific target
	 *
	 * @param string  $appname  the application name for the location
	 * @param string  $location1 the location name of origin
	 * @param string  $location2 the location name of target
	 * @param integer $id       id of the referenced item
	 * @param integer $role     role of the referenced item ('origin' or 'target')
	 *
	 * @return array targets
	 */
	public function get_specific_relation($appname, $location1, $location2, $id, $role = 'origin')
	{

		return $this->interLinkService->get_specific_relation($appname, $location1, $location2, $id, $role);
	}

	/**
	 * Get location name
	 *
	 * @param array   $linkend_location the location
	 * @param integer $id			   the id of the referenced item
	 *
	 * @return string the linkt to the the related item
	 */
	public function get_location_name($location)
	{
		return $this->interLinkService->get_location_name($location);
	}

	/**
	 * Get relation of the interlink
	 *
	 * @param integer $location_id the location
	 * @param integer $id			the id of the referenced item
	 *
	 * @return string the linkt to the the related item
	 */
	public function get_location_link($location_id, $id, $action = 'view')
	{
		return $this->interLinkService->get_location_link($location_id, $id, $action);
	}

	/**
	 * Get relation of the interlink
	 *
	 * @param array   $linkend_location the location
	 * @param integer $id			   the id of the referenced item
	 *
	 * @return string the linkt to the the related item
	 */
	public function get_relation_link($linkend_location, $id, $function = 'edit', $external = false)
	{
		return $this->interLinkService->get_relation_link($linkend_location, $id, $function, $external);
	}

	/**
	 * Get additional info of the linked item
	 *
	 * @param array   $linkend_location the location
	 * @param integer $id			   the id of the referenced item
	 *
	 * @return string info of the linked item
	 */
	public function get_relation_info($linkend_location, $id = 0)
	{
		return $this->interLinkService->get_relation_info($linkend_location, $id);
	}

	/**
	 * Get entry date of the related item
	 *
	 * @param string  $appname  		  the application name for the location
	 * @param string  $origin_location the location name of the origin
	 * @param string  $target_location the location name of the target
	 * @param integer $id			  id of the referenced item (parent)
	 * @param integer $entity_id		  id of the entity type if the type is a entity
	 * @param integer $cat_id		  id of the entity_category type if the type is a entity
	 *
	 * @return array date_info and link to related items
	 */
	public function get_child_date($appname, $origin_location, $target_location, $id, $entity_id = '', $cat_id = '')
	{
		return $this->interLinkService->get_child_date($appname, $origin_location, $target_location, $id, $entity_id, $cat_id);
	}


	/**
	 * Add link to item
	 *
	 * @param array  $data	link data
	 * @param object $db		db-object - used to keep the operation within the callers transaction
	 *
	 * @return bool true on success, false otherwise
	 */
	public function add($data, $db = '')
	{
		return $this->interLinkService->add($data, $db);
	}

	/**
	 * Delete link at origin
	 *
	 * @param string  $appname   the application name for the location
	 * @param string  $location1 the location name of origin
	 * @param string  $location2 the location name of target
	 * @param integer $id        id of the referenced item
	 * @param object $db			db-object - used to keep the operation within the callers transaction
	 *
	 * @return array interlink data
	 */
	public function delete_at_origin($appname, $location1, $location2, $id, $db = '')
	{
		return $this->interLinkService->delete_at_origin($appname, $location1, $location2, $id, $db);
	}

	/**
	 * Delete all relations based on a given start point (location1 and item1)
	 *
	 * @param string  $appname   the application name for the location
	 * @param string  $location  the location name of target
	 * @param integer $id        id of the referenced item
	 * @param object $db			db-object - used to keep the operation within the callers transaction
	 *
	 * @return array interlink data
	 */
	public function delete_at_target($appname, $location, $id, $db = '')
	{
		return $this->interLinkService->delete_at_target($appname, $location, $id, $db);
	}

	/**
	 * Delete all relations based on a given end point (location2 and item2)
	 *
	 * @param string  $appname   the application name for the location
	 * @param string  $location  the location name of target
	 * @param integer $id        id of the referenced item
	 * @param object $db			db-object - used to keep the operation within the callers transaction
	 *
	 * @return array interlink data
	 */
	public function delete_from_target($appname, $location, $id, $db = '')
	{
		return $this->interLinkService->delete_from_target($appname, $location, $id, $db);
	}
}
