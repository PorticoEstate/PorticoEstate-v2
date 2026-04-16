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
 * @subpackage entity
 * @version $Id$
 */

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\controllers\Locations;

/**
 * Description
 * @package property
 */
class property_boentity
{

	var $start;
	var $query;
	var $filter;
	var $sort;
	var $order;
	var $cat_id;
	var $p_num;
	var $allrows;
	var $district_id;
	var $status;
	var $part_of_town_id;
	var $start_date;
	var $end_date;
	var $location_code;
	var $results;
	var $acl_location;
	var $soadmin_entity, $so, $bocommon, $solocation, $entity_id, $category_dir,
		$criteria_id, $uicols, $total_records, $org_unit_id, $use_session, $table, $attrib_id_field;
	public $org_units = array();
	public $org_unit;
	protected $xsl_rootdir;

	/**
	 * @var object $custom reference to custom fields object
	 */
	public $custom;
	var $public_functions		 = array(
		'read'					 => true,
		'read_single'			 => true,
		'save'					 => true,
		'delete'				 => true,
		'add_control'			 => true,
		'update_control_serie'	 => true,
		'get_attributes'		 => true,
		'set_geolocation'		 => true,
	);
	var $type_app				 = array();
	var $type, $locations_obj, $acl;
	protected $userSettings;
	private $location_relation_data	 = array();

	/**
	 * Constructor. Initialises ACL, session state, entity/category context, and helper objects.
	 *
	 * @param bool   $session   Whether to restore list state from session cache.
	 * @param string $type      Entity type key (e.g. 'entity').
	 * @param int    $entity_id Entity definition ID.
	 * @param int    $cat_id    Category ID within the entity.
	 */
	function __construct($session = false, $type = '', $entity_id = 0, $cat_id = 0)
	{
		$this->solocation	 = CreateObject('property.solocation');
		$this->bocommon		 = CreateObject('property.bocommon');
		$this->acl = Acl::getInstance();
		$this->userSettings = Settings::getInstance()->get('user');

		if (!$type)
		{
			$type = Sanitizer::get_var('type');
		}
		if (!$entity_id)
		{
			$entity_id = Sanitizer::get_var('entity_id', 'int');
		}
		if (!$cat_id)
		{
			$cat_id = Sanitizer::get_var('cat_id', 'int');
		}
		$start			 = Sanitizer::get_var('start', 'int', 'REQUEST', 0);
		$query			 = Sanitizer::get_var('query');
		$sort			 = Sanitizer::get_var('sort');
		$order			 = Sanitizer::get_var('order');
		$filter			 = Sanitizer::get_var('filter', 'int');
		$district_id	 = Sanitizer::get_var('district_id', 'int');
		$part_of_town_id = Sanitizer::get_var('part_of_town_id', 'int');
		$status			 = Sanitizer::get_var('status');
		$start_date		 = Sanitizer::get_var('start_date');
		$end_date		 = Sanitizer::get_var('end_date');
		$allrows		 = Sanitizer::get_var('allrows', 'bool');
		$criteria_id	 = Sanitizer::get_var('criteria_id');
		$p_num			 = Sanitizer::get_var('p_num');
		$org_unit_id	 = Sanitizer::get_var('org_unit_id', 'int');

		$this->locations_obj = new Locations();

		if ($location_id = Sanitizer::get_var('location_id', 'int'))
		{
			$location_info	 = $this->locations_obj->get_name($location_id);
			$location_arr	 = explode('.', $location_info['location']);
			$type			 = $location_arr[1];
			$entity_id		 = $location_arr[2];
			$cat_id			 = $location_arr[3];
		}

		$this->criteria_id = isset($criteria_id) && $criteria_id ? $criteria_id : '';

		$location_code	 = Sanitizer::get_var('location_code');
		$this->so		 = CreateObject('property.soentity', $entity_id, $cat_id);
		$this->type_app	 = $this->so->get_type_app();

		$this->type = isset($type) && $type && $this->type_app[$type] ? $type : 'entity';

		$this->acl_location = ".{$type}.{$entity_id}.{$cat_id}";

		$this->location_code = isset($location_code) && $location_code ? $location_code : '';

		$this->soadmin_entity			 = CreateObject('property.soadmin_entity', $entity_id, $cat_id);
		$this->custom					 = &$this->so->custom;
		$this->soadmin_entity->type		 = $this->type;
		$this->soadmin_entity->type_app	 = $this->type_app;
		$this->so->type					 = $this->type;

		$this->category_dir = "{$this->type}_{$entity_id}_{$cat_id}";

		if ($session)
		{
			$this->read_sessiondata();
			$this->use_session = true;
		}

		if (isset($_POST['start']) || isset($_GET['start']))
		{
			$this->start = $start;
		}
		if (isset($_POST['query']) || isset($_GET['query']))
		{
			$this->query = $query;
		}
		else if ($location_code)
		{
			$this->query = $location_code;
		}

		if (isset($_POST['filter']) || isset($_GET['filter']))
		{
			$this->filter = $filter;
		}
		if (isset($_POST['sort']) || isset($_GET['sort']))
		{
			$this->sort = $sort;
		}
		if (isset($_POST['order']) || isset($_GET['order']))
		{
			$this->order = $order;
		}
		if ($cat_id || isset($_POST['cat_id']) || isset($_GET['cat_id']))
		{
			$this->cat_id = $cat_id;
		}
		if (isset($_POST['district_id']) || isset($_GET['district_id']))
		{
			$this->district_id = $district_id;
		}
		if (isset($_POST['part_of_town_id']) || isset($_GET['part_of_town_id']))
		{
			$this->part_of_town_id = $part_of_town_id;
		}
		if (isset($_POST['criteria_id']) || isset($_GET['criteria_id']))
		{
			$this->criteria_id = $criteria_id;
		}
		if ($entity_id)
		{
			$this->entity_id = $entity_id;
		}
		if (isset($_POST['status']) || isset($_GET['status']))
		{
			$this->status = $status;
		}
		if (isset($_POST['start_date']) || isset($_GET['start_date']))
		{
			$this->start_date = $start_date;
		}
		if (isset($_POST['end_date']) || isset($_GET['end_date']))
		{
			$this->end_date = $end_date;
		}
		if (isset($_POST['p_num']) || isset($_GET['p_num']))
		{
			$this->p_num = $p_num;
		}
		if ($allrows)
		{
			$this->allrows = $allrows;
		}
		if (isset($_POST['org_unit_id']) || isset($_GET['org_unit_id']))
		{
			$this->org_unit_id = $org_unit_id;
		}
		$this->xsl_rootdir = PHPGW_SERVER_ROOT . '/property/templates/base';
	}

	/**
	 * Persist list state (pagination, filters, ordering) to the session cache.
	 *
	 * @param array $data Associative array of state values to store.
	 * @return void
	 */
	function save_sessiondata($data)
	{
		if ($this->use_session)
		{
			Cache::session_set($this->category_dir, 'session_data', $data);
		}
	}

	/**
	 * Restore list state (pagination, filters, ordering) from the session cache.
	 *
	 * @return void
	 */
	function read_sessiondata()
	{
		$data = Cache::session_get($this->category_dir, 'session_data');

		$this->start		 = isset($data['start']) ? $data['start'] : '';
		$this->query		 = isset($data['query']) ? $data['query'] : '';
		$this->filter		 = isset($data['filter']) ? $data['filter'] : '';
		$this->sort			 = isset($data['sort']) ? $data['sort'] : '';
		$this->order		 = isset($data['order']) ? $data['order'] : '';
		$this->district_id	 = isset($data['district_id']) ? $data['district_id'] : '';
		$this->status		 = isset($data['status']) ? $data['status'] : '';
		$this->start_date	 = isset($data['start_date']) ? $data['start_date'] : '';
		$this->end_date		 = isset($data['end_date']) ? $data['end_date'] : '';
		$this->criteria_id	 = isset($data['criteria_id']) ? $data['criteria_id'] : '';

		//$this->allrows		= $data['allrows'];
	}

	/**
	 * Read geolocation coordinates from the request and delegate to the storage layer.
	 *
	 * Reads location_id, component_id, latitude, and longitude from the current
	 * request. Requires ACL_EDIT permission on the entity ACL location.
	 *
	 * @return array Associative array with key 'status' set to 'ok' or 'error'.
	 */
	function set_geolocation()
	{
		$location_id = Sanitizer::get_var('location_id', 'int');
		$component_id = Sanitizer::get_var('component_id', 'int');
		$latitude = Sanitizer::get_var('latitude', 'float');
		$longitude = Sanitizer::get_var('longitude', 'float');

		$ok = false;

		if ($this->acl->check($this->acl_location, ACL_EDIT, 'property'))
		{
			$ok = $this->so->set_geolocation($location_id, $component_id, $latitude, $longitude);
		}

		return array(
			'status' => $ok ? 'ok' : 'error'
		);
	}

	/**
	 * Build a multi-select column visibility list for the entity list view.
	 *
	 * @param array|string $selected  Previously selected column IDs.
	 * @param int          $entity_id Entity definition ID.
	 * @param int          $cat_id    Category ID within the entity.
	 * @param bool         $allrows   Whether all rows are being shown (unused).
	 * @return array Formatted list suitable for a multi-select UI widget.
	 */
	function column_list($selected = '', $entity_id = '', $cat_id = '', $allrows = '')
	{
		if (!$cat_id)
		{
			return array();
		}
		$userSettings = Settings::getInstance()->get('user');
		if (!$selected)
		{
			$selected = $userSettings['preferences'][$this->type_app[$this->type]]["{$this->type}_columns_{$this->entity_id}_{$this->cat_id}"];
		}
		$filter	 = array('list' => ''); // translates to "list IS NULL"
		$columns = $this->custom->find($this->type_app[$this->type], ".{$this->type}.{$entity_id}.{$cat_id}", 0, '', '', '', true, false, $filter);
		$columns = array_merge($columns, $this->get_column_list());
		foreach ($columns as &$column)
		{
			$column['name'] = $column['descr'] ? $column['descr'] : $column['input_text'];
		}
		$column_list = $this->bocommon->select_multi_list($selected, $columns);
		return $column_list;
	}

	/**
	 * Return the list of built-in (non-custom) columns available for the entity list view.
	 *
	 * Includes location-relation columns from the system cache and a 'user_name' column.
	 *
	 * @return array Indexed array of column definition arrays.
	 */
	function get_column_list()
	{
		$columns = array();

		// defined i property_bocommon::generate_sql()
		$location_relation_data = Cache::system_get('property', 'location_relation_data');

		$this->location_relation_data = $location_relation_data && is_array($location_relation_data) ? $location_relation_data : array();

		if ($this->location_relation_data && is_array($this->location_relation_data))
		{
			foreach ($this->location_relation_data as $entry)
			{
				$columns[$entry['name']] = array(
					'id'		 => $entry['name'],
					'input_type' => 'text',
					'name'		 => $entry['name'],
					'descr'		 => $entry['descr'],
					'statustext' => $entry['descr'],
					'align'		 => '',
					'datatype'	 => $entry['datatype'],
					'sortable'	 => false,
					'exchange'	 => false,
					'formatter'	 => '',
					'classname'	 => ''
				);
			}
		}

		$columns['user_name'] = array(
			'id'		 => 'user_name',
			'input_type' => 'text',
			'name'		 => 'user_name',
			'descr'		 => lang('User'),
			'statustext' => lang('User'),
			'align'		 => '',
			'datatype'	 => 'user',
			'sortable'	 => false,
			'exchange'	 => false,
			'formatter'	 => '',
			'classname'	 => ''
		);

		return $columns;
	}

	/**
	 * Build an HTML select or filter widget listing available entity categories.
	 *
	 * @param string $format   Widget format: 'select' or 'filter'.
	 * @param mixed  $selected Currently selected category ID.
	 * @param bool   $required Whether the field should be marked as required.
	 * @return array Formatted options list for use with bocommon::select_list().
	 */
	function select_category_list($format = '', $selected = '', $required = '')
	{
		switch ($format)
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('cat_select'), $this->xsl_rootdir);
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('cat_filter'), $this->xsl_rootdir);
				break;
		}

		$categories = $this->soadmin_entity->read_category(array(
			'allrows'	 => true,
			'entity_id'	 => $this->entity_id,
			'required'	 => $required,
			'order'		 => 'name',
			'sort'		 => 'ASC'
		));

		return $this->bocommon->select_list($selected, $categories);
	}

	/**
	 * Build an HTML select or filter widget listing available entity statuses.
	 *
	 * @param string $format   Widget format: 'select' or 'filter'.
	 * @param mixed  $selected Currently selected status ID.
	 * @return array Formatted options list for use with bocommon::select_list().
	 */
	function select_status_list($format = '', $selected = '')
	{
		switch ($format)
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('status_select'), $this->xsl_rootdir);
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('status_filter'), $this->xsl_rootdir);
				break;
		}

		$status_entries = $this->so->select_status_list($this->entity_id, $this->cat_id);

		return $this->bocommon->select_list($selected, $status_entries);
	}

	/**
	 * Build a select widget listing the available search-criteria types.
	 *
	 * Available criteria: 'vendor', 'contact' (ab), and 'organisation' (abo).
	 *
	 * @param mixed $selected Currently selected criteria ID.
	 * @return array Formatted options list for use with bocommon::select_list().
	 */
	function get_criteria_list($selected = '')
	{
		$criteria = array(
			array(
				'id'	 => 'vendor',
				'name'	 => lang('vendor')
			),
			array(
				'id'	 => 'ab',
				'name'	 => lang('contact')
			),
			array(
				'id'	 => 'abo',
				'name'	 => lang('organisation')
			)
		);
		return $this->bocommon->select_list($selected, $criteria);
	}

	/**
	 * Recursively collect all descendant org-unit IDs from a tree node.
	 *
	 * Results are accumulated into $this->org_units.
	 *
	 * @param array $data Array of org-unit tree nodes, each optionally containing a 'children' key.
	 * @return void
	 */
	private function _get_children($data = array())
	{
		foreach ($data as $entry)
		{
			$this->org_units[] = $entry['id'];
			if (isset($entry['children']) && $entry['children'])
			{
				$this->_get_children($entry['children']);
			}
		}
	}

	/**
	 * Read a paginated list of entities from the storage layer.
	 *
	 * Resolves org-unit subtrees when an org_unit_id filter is active, then
	 * delegates to soentity::read_eav() or soentity::read() based on category type.
	 *
	 * @param array $data Optional overrides for filter, query, start, and order parameters.
	 * @return array List of entity records with translated custom-field values.
	 */
	function read($data = array())
	{
		if ($this->org_unit_id && !$this->org_units)
		{
			$_org_unit_id		 = (int)$this->org_unit_id;
			$_subs				 = execMethod('property.sogeneric.read_tree', array(
				'node_id'	 => $_org_unit_id,
				'type'		 => 'org_unit'
			));
			$this->org_units[]	 = $_org_unit_id;
			foreach ($_subs as $entry)
			{
				$this->org_units[] = $entry['id'];
				if (isset($entry['children']) && $entry['children'])
				{
					$this->_get_children($entry['children']);
				}
			}
		}

		static $location_data	 = array();
		static $org_units_data	 = array();

		if (isset($this->allrows) && $this->allrows)
		{
			$data['allrows'] = true;
		}

		$custom		 = createObject('phpgwapi.custom_fields');
		$attrib_data = $custom->find($this->type_app[$this->type], ".{$this->type}.{$this->entity_id}.{$this->cat_id}", 0, '', '', '', true, true);

		$category = $this->soadmin_entity->read_single_category($this->entity_id, $this->cat_id);

		$attrib_filter		 = array();
		$javascript_action	 = array();
		$location_id		 = $this->locations_obj->get_id($this->type_app[$this->type], ".{$this->type}.{$this->entity_id}.{$this->cat_id}");
		if ($attrib_data)
		{
			foreach ($attrib_data as $attrib)
			{
				if ($attrib['datatype'] == 'LB' || $attrib['datatype'] == 'R')
				{
					if ($_attrib_filter_value = Sanitizer::get_var($attrib['column_name'], 'int'))
					{
						if ($category['is_eav'])
						{
							//	$attrib_filter[] = "xmlexists('//{$attrib['column_name']}[text() = ''$_attrib_filter_value'']' PASSING BY REF xml_representation)";
							$attrib_filter[] = "json_representation->>'{$attrib['column_name']}' = '{$_attrib_filter_value}'";
						}
						else
						{
							$attrib_filter[] = "fm_{$this->type}_{$this->entity_id}_{$this->cat_id}.{$attrib['column_name']} = '{$_attrib_filter_value}'";
						}
					}
				}
				else if ($attrib['datatype'] == 'CH')
				{
					if ($_attrib_filter_value = Sanitizer::get_var($attrib['column_name'], 'int'))
					{
						$db = CreateObject('phpgwapi.db');
						if ($category['is_eav'])
						{
							$attrib_filter[] = "json_representation->>'{$attrib['column_name']}' {$db->like} '%,{$_attrib_filter_value},%'";
						}
						else
						{
							$attrib_filter[] = "fm_{$this->type}_{$this->entity_id}_{$this->cat_id}.{$attrib['column_name']} {$db->like} '%,{$_attrib_filter_value},%'";
						}
					}
				}

				if ($attrib['datatype'] == 'link')
				{
					if ($attrib['javascript_action'])
					{
						$javascript_action[$attrib['name']]	 = $attrib['javascript_action'];
						$js									 = <<<JS

							javascript_action_{$attrib['name']} = function(id,location_code)
							{
JS;
						$js									 .= str_replace(array('__entity_id__', '__cat_id__', '__location_id__'), array(
							$this->entity_id,
							$this->cat_id,
							$location_id
						), $attrib['javascript_action']);

						$js .= <<<JS
							}
JS;

						phpgwapi_js::getInstance()->add_code('', $js);
					}
				}
			}
		}
		$entity = $this->so->read(
			array(
				'start'				 => $data['start'],
				'query'				 => $data['query'],
				'sort'				 => $data['sort'],
				'order'				 => $data['order'],
				'parent_location_id' => $data['parent_location_id'],
				'parent_id'			 => $data['parent_id'],
				'filter'			 => $this->filter,
				'cat_id'			 => $this->cat_id,
				'district_id'		 => $this->district_id,
				'part_of_town_id'	 => $this->part_of_town_id,
				'lookup'			 => isset($data['lookup']) ? $data['lookup'] : '',
				'allrows'			 => isset($data['allrows']) ? $data['allrows'] : '',
				'results'			 => $data['results'],
				'entity_id'			 => $this->entity_id,
				'status'			 => $this->status,
				'start_date'		 => $this->bocommon->date_to_timestamp($data['start_date']),
				'end_date'			 => $this->bocommon->date_to_timestamp($data['end_date']),
				'dry_run'			 => $data['dry_run'],
				'type'				 => $this->type,
				'location_code'		 => $this->location_code,
				'criteria_id'		 => $this->criteria_id,
				'attrib_filter'		 => $attrib_filter,
				'p_num'				 => $this->p_num,
				'control_registered' => isset($data['control_registered']) ? $data['control_registered'] : '',
				'control_id'		 => isset($data['control_id']) ? $data['control_id'] : '',
				'org_units'			 => $this->org_units
			)
		);

		$this->total_records = $this->so->total_records;
		$this->uicols		 = $this->so->uicols;

		$user_columns	 = isset($this->userSettings['preferences'][$this->type_app[$this->type]]["{$this->type}_columns_{$this->entity_id}_{$this->cat_id}"]) ? (array)$this->userSettings['preferences'][$this->type_app[$this->type]]["{$this->type}_columns_{$this->entity_id}_{$this->cat_id}"] : array();
		$custom_cols	 = $this->get_column_list();

		//_debug_array($user_columns);
		//_debug_array($column_list);

		$cols_extra			 = $this->so->cols_extra;
		$cols_return_lookup	 = $this->so->cols_return_lookup;

		foreach ($custom_cols as $col_id => $col_info)
		{
			if (in_array($col_id, $user_columns))
			{
				$this->uicols['input_type'][]	 = 'text';
				$this->uicols['name'][]			 = $col_id;
				$this->uicols['descr'][]		 = $custom_cols[$col_id]['descr'];
				$this->uicols['statustext'][]	 = $custom_cols[$col_id]['descr'];
				$this->uicols['exchange'][]		 = false;
				$this->uicols['align'][]		 = '';
				$this->uicols['datatype'][]		 = $custom_cols[$col_id]['datatype'];
				$this->uicols['formatter'][]	 = '';
				$this->uicols['classname'][]	 = '';
				$this->uicols['sortable'][]		 = false;
				$cols_extra[]					 = $col_id;
			}
		}

		$sogeneric = CreateObject('property.sogeneric');
		$sogeneric->get_location_info('org_unit');

		foreach ($entity as &$entry)
		{
			//_debug_array($entry);die();
			if (isset($entry['location_code']))
			{
				if (!isset($location_data[$entry['location_code']]))
				{
					$location_data[$entry['location_code']] = $this->solocation->read_single($entry['location_code']);
				}
				for ($j = 0; $j < count($cols_extra); $j++)
				{
					$entry[$cols_extra[$j]] = $location_data[$entry['location_code']][$cols_extra[$j]];
				}

				if ($cols_return_lookup)
				{
					for ($k = 0; $k < count($cols_return_lookup); $k++)
					{
						$entry[$cols_return_lookup[$k]] = $location_data[$entry['location_code']][$cols_return_lookup[$k]];
					}
				}
			}
			if (isset($entry['org_unit_id']))
			{
				if (!isset($org_units_data[$entry['org_unit_id']]))
				{
					$org_unit										 = $sogeneric->read_single(array('id' => $entry['org_unit_id']));
					$org_units_data[$entry['org_unit_id']]['name']	 = $org_unit['name'];
				}
				$entry['org_unit'] = $org_units_data[$entry['org_unit_id']]['name'];
			}

			if (isset($entry['p_location_id']) && isset($entry['p_id']) && $entry['p_id'])
			{
				//static cached within so-class
				$entry['p_location'] = $this->so->get_short_description(array(
					'location_id'	 => $entry['p_location_id'],
					'id'			 => $entry['p_id']
				));
			}

			foreach ($javascript_action as $_name => $_action)
			{
				$entry[$_name]								 = "javascript_action_{$_name}({$entry['id']},{$entry['location_code']})";
				$this->uicols['javascript_action'][$_name]	 = true;
			}

			if ($entry['user_id'])
			{
				$accounts_obj = new Accounts();
				$entry['user_name'] = $accounts_obj->get($entry['user_id'])->__toString();
			}
		}

		return $entity;
	}

	/**
	 * Return all custom attributes defined for the current entity/category.
	 *
	 * @return array Indexed array of attribute definition arrays, ordered by attrib_sort ASC.
	 */
	function get_attributes()
	{
		return $this->custom->find($this->type_app[$this->type], ".{$this->type}.{$this->entity_id}.{$this->cat_id}", 0, '', 'ASC', 'attrib_sort', true, true);
	}

	/**
	 * Merge full attribute metadata into an array of attribute values.
	 *
	 * @param array &$values_attribute Attribute values keyed by attrib_id; merged in place.
	 * @return void
	 */
	function get_attribute_information(&$values_attribute)
	{
		$_attributes = $this->get_attributes();

		foreach ($values_attribute as $attrib_id => &$attribute)
		{
			foreach ($_attributes as $_key =>  $_attribute)
			{
				if ($attrib_id == $_attribute['id'])
				{
					$attribute = array_merge($_attribute, $attribute);
				}
			}
		}
	}

	/**
	 * Read a single entity record with custom attributes, location, files, and interlinks.
	 *
	 * @param array $data   Must include 'entity_id', 'cat_id', 'id', and 'view'.
	 * @param array $values Optional initial values to merge with the retrieved record.
	 * @return array Full entity record including attributes, location data, files, and relations.
	 */
	function read_single($data, $values = array())
	{
		$values['attributes'] = $this->custom->find($this->type_app[$this->type], ".{$this->type}.{$data['entity_id']}.{$data['cat_id']}", 0, '', 'ASC', 'attrib_sort', true, true);
		if (isset($data['id']) && $data['id'])
		{
			$values = $this->so->read_single($data, $values);
		}
		$values = $this->custom->prepare($values, $this->type_app[$this->type], ".{$this->type}.{$data['entity_id']}.{$data['cat_id']}", $data['view']);

		if ($values['org_unit_id'])
		{
			$bogeneric						 = CreateObject('property.sogeneric');
			$bogeneric->get_location_info('org_unit');
			$org_unit						 = $bogeneric->read_single(array('id' => $values['org_unit_id']));
			$values['org_unit_name']		 = $org_unit['name'];
			$values['org_unit_name_path']	 = $org_unit['name'];
			if ($org_unit['parent_id'])
			{
				$path = $bogeneric->get_path(array('type' => 'org_unit', 'id' => $org_unit['parent_id']));
				if ($path)
				{
					$values['org_unit_name_path'] .= '::' . implode(' > ', $path);
				}
			}
		}

		if ($values['location_code'])
		{
			$values['location_data'] = $this->solocation->read_single($values['location_code']);
			if ($values['tenant_id'])
			{
				$tenant_data								 = $this->bocommon->read_single_tenant($values['tenant_id']);
				$values['location_data']['tenant_id']		 = $values['tenant_id'];
				$values['location_data']['contact_phone']	 = $values['contact_phone'];
				$values['location_data']['last_name']		 = $tenant_data['last_name'];
				$values['location_data']['first_name']		 = $tenant_data['first_name'];
			}
		}

		//old
		if ($values['p_num'])
		{
			$soadmin_entity										 = CreateObject('property.soadmin_entity');
			$soadmin_entity->type								 = 'entity';
			$soadmin_entity->type_app							 = 'property';
			$category											 = $soadmin_entity->read_single_category($values['p_entity_id'], $values['p_cat_id']);
			$values['p'][$values['p_entity_id']]['p_num']		 = $values['p_num'];
			$values['p'][$values['p_entity_id']]['p_entity_id']	 = $values['p_entity_id'];
			$values['p'][$values['p_entity_id']]['p_cat_id']	 = $values['p_cat_id'];
			$values['p'][$values['p_entity_id']]['p_cat_name']	 = $category['name'];
		}

		//new
		if ($values['p_id'] && $values['p_location_id'])
		{
			$p_location									 = $this->locations_obj->get_name($values['p_location_id']);
			$p__location								 = explode('.', $p_location['location']);
			$values['p'][$p__location[2]]['p_num']		 = $values['p_id'];
			$values['p'][$p__location[2]]['p_entity_id'] = $p__location[2];
			$values['p'][$p__location[2]]['p_cat_id']	 = $p__location[3];
			$values['p'][$p__location[2]]['p_cat_name']	 = $p_location['descr'];
			if ($short_description							 = $this->so->get_short_description(array(
				'location_id'	 => $values['p_location_id'],
				'id'			 => $values['p_id']
			)))
			{
				$values['p'][$p__location[2]]['p_cat_name'] .= "::$short_description";
			}
		}

		$vfs				 = CreateObject('phpgwapi.vfs');
		$vfs->override_acl	 = 1;

		$loc1 = isset($values['location_data']['loc1']) && $values['location_data']['loc1'] ? $values['location_data']['loc1'] : 'dummy';

		if ($this->type_app[$this->type] == 'catch')
		{
			$loc1 = 'dummy';
		}

		$files = $vfs->ls(array(
			'string'	 => "/property/{$this->category_dir}/{$loc1}/{$data['id']}",
			'relatives'	 => array(RELATIVE_NONE)
		));

		$vfs->override_acl = 0;

		$values['jasperfiles']	 = array();
		$values['files']		 = array();
		foreach ($files as $file)
		{
			if (strpos($file['name'], 'jasper::') === 0) // check for jasper
			{
				$values['jasperfiles'][] = array(
					'name' => $file['name']
				);
			}
			else
			{
				$values['files'][] = array(
					'name'		 => $file['name'],
					'directory'	 => $file['directory'],
					'file_id'	 => $file['file_id'],
					'mime_type'	 => $file['mime_type']
				);
			}
		}

		$interlink				 = CreateObject('property.interlink');
		$values['origin_data']	 = $interlink->get_relation($this->type_app[$this->type], ".{$this->type}.{$data['entity_id']}.{$data['cat_id']}", $data['id'], 'origin');
		$values['target']		 = $interlink->get_relation($this->type_app[$this->type], ".{$this->type}.{$data['entity_id']}.{$data['cat_id']}", $data['id'], 'target');
		return $values;
	}

	/**
	 * Arrange attributes within groups
	 *
	 * @param string  $location    the name of the location of the attribute
	 * @param array   $attributes  the array of the attributes to be grouped
	 *
	 * @return array the grouped attributes
	 */
	public function get_attribute_groups($location, $attributes = array())
	{
		return $this->custom->get_attribute_groups($this->type_app[$this->type], $location, $attributes);
	}

	/**
	 * Save checklist stage attribute values for an entity item.
	 *
	 * @param int   $item_id                 ID of the entity item.
	 * @param array $values_checklist_stage  Attribute values keyed by stage_id.
	 * @param array &$receipt               Receipt array updated with errors or confirmations.
	 * @return void
	 */
	function save_checklist($item_id, $values_checklist_stage, &$receipt)
	{
		foreach ($values_checklist_stage as $stage_id => $values_attribute)
		{
			$values_attribute = $this->custom->convert_attribute_save($values_attribute);
			$this->so->save_checklist($item_id, $stage_id, $values_attribute, $receipt);
		}
	}

	/**
	 * Save (add or edit) an entity record with custom attributes.
	 *
	 * Converts location arrays, timestamps, and attribute values before persisting.
	 * Runs pre- and post-commit custom functions if configured.
	 *
	 * @param array  $values           Core entity field values.
	 * @param array  $values_attribute Custom attribute values keyed by attrib_id.
	 * @param string $action           'add' or 'edit'.
	 * @param int    $entity_id        Entity definition ID.
	 * @param int    $cat_id           Category ID within the entity.
	 * @return array Receipt array with 'id' and optional 'error' keys.
	 */
	function save($values, $values_attribute, $action, $entity_id, $cat_id)
	{
		if (is_array($values['location']))
		{
			$location = array();
			foreach ($values['location'] as $value)
			{
				if ($value)
				{
					$location[] = $value;
				}
			}
		}

		if (empty($values['location_code']))
		{
			$values['location_code'] = !empty($location) ? implode('-', $location) : '';
		}

		//FIXME
		$values['date'] = $this->bocommon->date_to_timestamp($values['date']);

		if (is_array($values_attribute))
		{
			$values_attribute = $this->custom->convert_attribute_save($values_attribute);
		}

		$criteria = array(
			'appname'	 => $this->type_app[$this->type],
			'location'	 => ".{$this->type}.{$entity_id}.{$cat_id}",
			'allrows'	 => true
		);


		$custom_functions = createObject('phpgwapi.custom_functions')->find($criteria);

		foreach ($custom_functions as $entry)
		{
			// prevent path traversal
			if (preg_match('/\.\./', $entry['file_name']))
			{
				continue;
			}

			$file = PHPGW_SERVER_ROOT . "/{$this->type_app[$this->type]}/inc/custom/{$this->userSettings['domain']}/{$entry['file_name']}";

			if ($entry['active'] && is_file($file) && !$entry['client_side'] && $entry['pre_commit'])
			{
				require_once $file;
			}
		}


		if ($action == 'edit')
		{
			$receipt = $this->so->edit($values, $values_attribute, $entity_id, $cat_id);
		}
		else
		{
			$receipt		 = $this->so->add($values, $values_attribute, $entity_id, $cat_id);
			$values['id']	 = $receipt['id'];
		}

		reset($custom_functions);
		foreach ($custom_functions as $entry)
		{
			// prevent path traversal
			if (preg_match('/\.\./', $entry['file_name']))
			{
				continue;
			}

			$file = PHPGW_SERVER_ROOT . "/{$this->type_app[$this->type]}/inc/custom/{$this->userSettings['domain']}/{$entry['file_name']}";

			if ($entry['active'] && is_file($file) && !$entry['client_side'] && !$entry['pre_commit'])
			{
				require_once $file;
			}
		}

		return $receipt;
	}

	/**
	 * Delete an entity record by ID.
	 *
	 * @param int $id ID of the record to delete.
	 * @return void
	 */
	function delete($id)
	{
		$this->so->delete($this->entity_id, $this->cat_id, $id);
	}

	/**
	 * Generate a new sequential ID for an entity category record.
	 *
	 * @param array $data Must include 'cat_id'.
	 * @return mixed Generated ID, or null if cat_id is missing.
	 */
	function generate_id($data)
	{
		if ($data['cat_id'])
		{
			return $this->so->generate_id($data);
		}
	}

	/**
	 * Resolve the history-log type string for a given ACL location.
	 *
	 * @param string $acl_location ACL location string (e.g. '.entity.1.2').
	 * @throws Exception If the location does not map to a known history type.
	 * @return string History type string used to construct a historylog object.
	 */
	function get_history_type_for_location($acl_location)
	{
		switch ($acl_location)
		{
			case '.project.request':
				$history_type			 = 'request';
				break;
			case '.project.workorder':
				$history_type			 = 'workorder';
				break;
			case '.project':
				$history_type			 = 'project';
				break;
			case '.tts':
				$history_type			 = 'tts';
				break;
			case '.document':
				$history_type			 = 'document';
				break;
			case 'entity':
				$this->table			 = 'fm_entity_history';
				$this->attrib_id_field	 = ',history_attrib_id';
				break;
			case '.s_agreement':
				$history_type			 = 's_agreement';
				break;
			case '.s_agreement.detail':
				$history_type			 = 's_agreement';
			default:
				$history_type			 = str_replace('.', '_', substr($acl_location, -strlen($acl_location) + 1));
		}
		if (!$history_type)
		{
			throw new Exception(lang('Unknown history type for acl_location: %1', $acl_location));
		}
		return $history_type;
	}

	/**
	 * Return the change history for a single attribute on an entity record.
	 *
	 * Translates listbox and date values to human-readable form.
	 *
	 * @param array $data Must include 'acl_location', 'attrib_id', 'id', and 'detail_id'.
	 * @return array Array of history entries; also sets $this->total_records.
	 */
	function read_attrib_history($data)
	{
		$attrib_data	 = $this->custom->get($this->type_app[$this->type], $data['acl_location'], $data['attrib_id'], $inc_choices	 = true);
		$history_type	 = $this->get_history_type_for_location($data['acl_location']);
		$historylog		 = CreateObject('property.historylog', $history_type);
		$history_values	 = $historylog->return_array(array(), array('SO'), 'history_timestamp', 'DESC', $data['id'], $data['attrib_id'], $data['detail_id']);

		if ($attrib_data['column_info']['type'] == 'LB')
		{
			foreach ($history_values as &$value_set)
			{
				foreach ($attrib_data['choice'] as $choice)
				{
					if ($choice['id'] == $value_set['new_value'])
					{
						$value_set['new_value'] = $choice['value'];
					}
				}
			}
		}


		if ($attrib_data['column_info']['type'] == 'D')
		{
			foreach ($history_values as &$value_set)
			{
				$value_set['new_value'] = date($this->userSettings['preferences']['common']['dateformat'], strtotime($value_set['new_value']));
			}
		}

		reset($history_values);
		$this->total_records = count($history_values);
		return $history_values;
	}

	/**
	 * Delete a single attribute history record.
	 *
	 * @param array $data Must include 'acl_location' and 'history_id'.
	 * @return void
	 */
	function delete_history_item($data)
	{
		$history_type	 = $this->get_history_type_for_location($data['acl_location']);
		$historylog		 = CreateObject('property.historylog', $history_type);
		$historylog->delete_single_record($data['history_id']);
	}

	/**
	 * Read help text for a custom attribute.
	 *
	 * @param array $data Must include the attribute identifier expected by soentity.
	 * @return array Attribute help data.
	 */
	function read_attrib_help($data)
	{
		return $this->so->read_attrib_help($data);
	}

	/**
	 * Return entity records suitable for use as interlink targets.
	 *
	 * @param array $data Query parameters forwarded to soentity.
	 * @return array Entity records for linking.
	 */
	function read_entity_to_link($data)
	{
		return $this->so->read_entity_to_link($data);
	}

	/**
	 * Return inventory entries for an entity item, enriched with interlink location links.
	 *
	 * @param array $data Must include 'id' and 'location_id'; optionally 'inventory_id'.
	 * @return array Inventory entries with 'where' (HTML link) and 'where_name' fields added.
	 */
	public function get_inventory($data)
	{
		$values = $this->so->get_inventory($data);

		$interlink = CreateObject('property.interlink');

		foreach ($values as &$entry)
		{
			$link_info				 = $interlink->get_location_link($entry['p_location_id'], $entry['p_id'], 'view');
			$entry['where']			 = "<a href='{$link_info['link']}'>{$link_info['name']}</a>";
			$entry['where_name']	 = $link_info['name'];
			$entry['location_id']	 = $data['location_id'];
			$entry['id']			 = $data['id'];
		}

		return $values;
	}

	/**
	 * Add a new inventory entry for an entity item.
	 *
	 * Converts active_from and active_to date strings to timestamps before saving.
	 *
	 * @param array $values Inventory field values including 'active_from' and 'active_to'.
	 * @return mixed Insert result from soentity.
	 */
	public function add_inventory($values)
	{
		$values['active_from']	 = $this->bocommon->date_to_timestamp($values['active_from']);
		$values['active_to']	 = $this->bocommon->date_to_timestamp($values['active_to']);
		return $this->so->add_inventory($values);
	}

	/**
	 * Edit an existing inventory entry for an entity item.
	 *
	 * Converts active_from and active_to date strings to timestamps before saving.
	 *
	 * @param array $values Inventory field values including 'active_from' and 'active_to'.
	 * @return mixed Update result from soentity.
	 */
	public function edit_inventory($values)
	{
		$values['active_from']	 = $this->bocommon->date_to_timestamp($values['active_from']);
		$values['active_to']	 = $this->bocommon->date_to_timestamp($values['active_to']);
		return $this->so->edit_inventory($values);
	}

	/**
	 * Register a maintenance control on an entity item from the current request.
	 *
	 * Reads all parameters from the request, validates ACL_EDIT on '.admin',
	 * and delegates to socontrol::register_control_to_component().
	 *
	 * @return array Result array with 'status_kode', 'status', and 'msg' keys.
	 */
	public function add_control()
	{
		$entity_id		 = Sanitizer::get_var('entity_id', 'int');
		$cat_id			 = Sanitizer::get_var('cat_id', 'int');
		$id				 = Sanitizer::get_var('id', 'int');
		$type			 = Sanitizer::get_var('type', 'string', 'REQUEST', 'entity');
		$control_id		 = Sanitizer::get_var('control_id', 'int');
		$assigned_to	 = Sanitizer::get_var('control_responsible', 'int');
		$start_date		 = Sanitizer::get_var('control_start_date', 'string');
		$repeat_type	 = Sanitizer::get_var('repeat_type', 'int');
		$repeat_interval = Sanitizer::get_var('repeat_interval', 'int');
		$repeat_interval = $repeat_interval ? $repeat_interval : 1;
		$controle_time	 = Sanitizer::get_var('controle_time', 'float');
		$service_time	 = Sanitizer::get_var('service_time', 'float');

		$component_arr = $this->so->read_single(array(
			'entity_id'	 => $entity_id,
			'cat_id'	 => $cat_id,
			'id'		 => $id
		));

		$location_code = $component_arr['location_code'];

		if ($start_date)
		{
			phpgw::import_class('phpgwapi.datetime');
			$start_date = phpgwapi_datetime::date_to_timestamp($start_date);
		}

		$result = array(
			'status_kode'	 => 'error',
			'status'		 => lang('error'),
			'msg'			 => lang('Missing input')
		);

		if ($control_id && $assigned_to && $id)
		{
			if (!$this->acl->check('.admin', ACL_EDIT, 'property'))
			{
				$receipt['error'][]	 = true;
				$result				 = array(
					'status_kode'	 => 'error',
					'status'		 => lang('error'),
					'msg'			 => lang('you are not approved for this task')
				);
			}
			if (!$receipt['error'])
			{
				$location_id = $this->locations_obj->get_id($this->type_app[$type], ".{$type}.{$entity_id}.{$cat_id}");

				$so_control	 = CreateObject('controller.socontrol');
				$values		 = array(
					'register_component' => array("{$control_id}_{$location_id}_{$id}"),
					'assigned_to'		 => $assigned_to,
					'start_date'		 => $start_date,
					'repeat_type'		 => $repeat_type,
					'repeat_interval'	 => $repeat_interval,
					'controle_time'		 => $controle_time,
					'service_time'		 => $service_time,
					'duplicate'			 => true
				);
				//				_debug_array($values);
				if ($add		 = $so_control->register_control_to_component($values))
				{
					/*
						  if($add == ACL_ADD)
						  {
						  $this->add_check_list(array('location_id'=>$location_id, 'component_id' => $id, 'control_id' => $control_id, 'assigned_to' => $assigned_to, 'start_date' => $start_date, 'location_code' => $location_code));
						  }
						 */
					$result = array(
						'status_kode'	 => 'ok',
						'status'		 => 'Ok',
						'msg'			 => lang('updated')
					);
				}
				else
				{
					$result = array(
						'status_kode'	 => 'error',
						'status'		 => lang('error'),
						'msg'			 => 'Noe gikk galt'
					);
				}
			}
		}
		return $result;
	}

	/**
	 * Create a new check-list entry linked to a control and entity item.
	 *
	 * @param array $data Must include 'control_id', 'location_id', 'component_id',
	 *                    'assigned_to', 'start_date', and 'location_code'.
	 * @return int|false New check-list ID on success, false on validation failure.
	 */
	function add_check_list($data = array())
	{
		phpgw::import_class('controller.socheck_list');
		include_class('controller', 'check_list', 'inc/model/');

		$control_id		 = $data['control_id'];
		$type			 = 'component';
		$comment		 = '';
		$assigned_to	 = $data['assigned_to'];
		$billable_hours	 = Sanitizer::get_var('billable_hours', 'float');

		$deadline_date_ts	 = $data['start_date'];
		$planned_date_ts	 = $deadline_date_ts;
		$completed_date_ts	 = 0;

		$check_list = new controller_check_list();
		$check_list->set_control_id($control_id);
		$check_list->set_location_code($data['location_code']);
		$check_list->set_location_id($data['location_id']);
		$check_list->set_component_id($data['component_id']);

		$status = controller_check_list::STATUS_NOT_DONE;
		$check_list->set_status($status);
		$check_list->set_comment($comment);
		$check_list->set_deadline($deadline_date_ts);
		$check_list->set_planned_date($planned_date_ts);
		$check_list->set_completed_date($completed_date_ts);
		$check_list->set_assigned_to($assigned_to);
		$check_list->set_billable_hours($billable_hours);

		$socheck_list = CreateObject('controller.socheck_list');

		if ($check_list->validate() && $check_list_id = $socheck_list->store($check_list))
		{
			return $check_list_id;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Bulk-update a series of control schedule entries from the current request.
	 *
	 * Reads ids, action, assigned_to, start_date, repeat_interval, controle_time,
	 * and service_time from the request and delegates to socontrol.
	 *
	 * @return array Result array with 'status_kode', 'status', and 'msg' keys.
	 */
	function update_control_serie()
	{
		if ($start_date = Sanitizer::get_var('control_start_date', 'string'))
		{
			phpgw::import_class('phpgwapi.datetime');
			$start_date = phpgwapi_datetime::date_to_timestamp($start_date);
		}

		$so_control = CreateObject('controller.socontrol');

		$values	 = array(
			'ids'				 => Sanitizer::get_var('ids', 'int'),
			'action'			 => Sanitizer::get_var('action', 'string'),
			'assigned_to'		 => Sanitizer::get_var('control_responsible', 'int'),
			'start_date'		 => $start_date,
			//				'repeat_type'		=> Sanitizer::get_var('repeat_type', 'int'),
			'repeat_interval'	 => Sanitizer::get_var('repeat_interval', 'int'),
			'controle_time'		 => Sanitizer::get_var('controle_time', 'float'),
			'service_time'		 => Sanitizer::get_var('service_time', 'float')
		);
		$ret	 = $so_control->update_control_serie($values);

		if ($ret)
		{
			$result = array(
				'status_kode'	 => 'ok',
				'status'		 => 'Ok',
				'msg'			 => lang('updated')
			);
		}
		else
		{
			$result = array(
				'status_kode'	 => 'error',
				'status'		 => lang('error'),
				'msg'			 => 'Noe gikk galt'
			);
		}

		return $result;
	}

	/**
	 * Return a paginated ResultSet of entity items matching a QR code value.
	 *
	 * Queries all EAV attributes that store QR codes, filters matching items,
	 * and enriches each entry with a view link and register name.
	 *
	 * @param string $qr_code The QR code value to search for.
	 * @return array DataTables-compatible ResultSet array.
	 */
	public function get_items_per_qr($qr_code)
	{

		$attributes = $this->so->get_QR_attributes();

		$attrib_filter = array();
		$location_ids = array();
		foreach ($attributes as $column_name => $_location_ids)
		{
			$attrib_filter[] = "json_representation->>'{$column_name}' = '{$qr_code}'";
			$location_ids = array_merge($location_ids, $_location_ids);
		}

		$values = $this->so->get_items_per_qr($location_ids, $attrib_filter);


		foreach ($values as &$entry)
		{

			$loc_arr	 = $this->locations_obj->get_name($entry['location_id']);
			$type_arr	 = explode('.', $loc_arr['location']);

			$type	 = $type_arr[1];
			$entity_id	 = $type_arr[2];
			$cat_id		 = $type_arr[3];

			$entry['link'] = phpgw::link('/index.php', array(
				'menuaction' => 'property.uientity.view',
				'type'	 => $type,
				'entity_id'	 => $entity_id,
				'cat_id'	 => $cat_id,
				'id'		 => $entry['id']
			));

			$entry['register_name']  = $loc_arr['descr'];
		}

		$start			 = Sanitizer::get_var('startIndex', 'REQUEST', 'int', 0);
		$total_records	 = count($values);

		$num_rows = Sanitizer::get_var('length', 'int', 'REQUEST', 0);

		if ($num_rows == -1)
		{
			$out = $values;
		}
		else
		{
			if ($total_records > $num_rows)
			{
				$page		 = ceil(($start / $total_records) * ($total_records / $num_rows));
				$values_part = array_chunk($values, $num_rows);
				$out		 = $values_part[$page];
			}
			else
			{
				$out = $values;
			}
		}



		return array(
			'ResultSet' => array(
				"totalResultsAvailable"	 => $total_records,
				"totalRecords"			 => $total_records,
				'recordsReturned'		 => count($out),
				'pageSize'				 => $num_rows,
				'startIndex'			 => $start,
				'sortKey'				 => $this->order,
				'sortDir'				 => $this->sort,
				"Result"				 => $out
			)
		);
	}

	/**
	 * Return checklist data for an entity item at a specific location type.
	 *
	 * @param int $type_location_id Location type ID.
	 * @param int $item_id          Entity item ID.
	 * @return array Checklist data from soentity.
	 */
	function get_checklist_data($type_location_id, $item_id)
	{
		return $this->so->get_checklist_data($type_location_id, $item_id);
	}
}
