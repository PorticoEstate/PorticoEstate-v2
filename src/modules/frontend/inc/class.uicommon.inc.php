<?php

/**
 * Frontend : a simplified tool for end users.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2010 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package Frontend
 * @version $Id: class.uicommon.inc.php 14427 2015-11-19 23:07:42Z nelson224 $
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
use App\modules\phpgwapi\services\Cache;
//phpgw::import_class('phpgwapi.yui');
phpgw::import_class('phpgwapi.uicommon_jquery');
phpgw::import_class('frontend.bofrontend');
phpgw::import_class('frontend.bofellesdata');
phpgw::import_class('frontend.borental');

/**
 * Frontend main class
 *
 * @package Frontend
 */
//class frontend_uifrontend
abstract class frontend_uicommon extends phpgwapi_uicommon_jquery
{

	/**
	 * Used to save state of header (select box, ++) between requests
	 * @var array
	 */
	public $header_state;
	public $public_functions = array(
		'index' => true,
		'objectimg' => true,
		'save_profile' => true
	);

	var $config, $location_id, $tab_selected, $tabs_data, $tabs, $tabs_content, $menu, $selected;
	public function __construct()
	{
		parent::__construct();
		// This module uses XSLT templates
		Settings::getInstance()->update('flags', ['xslt_app' => true]);

		$this->config = CreateObject('phpgwapi.config', 'frontend');
		$this->config->read();
		$use_fellesdata = $this->config->config_data['use_fellesdata'];
		$logo_path = $this->config->config_data['logo_path'];

		// Get the mode: in frame or full screen
		$mode = Cache::session_get('frontend', 'noframework');
		$noframework = isset($mode) ? $mode : true;

		/* Get the tabs and check to see whether the user has specified a tab or has a selected tab on session */
		$tabs = $this->get_tabs();
		//print_r($tabs); die;
		$location_id = Sanitizer::get_var('location_id', 'int', 'REQUEST');
		$tab = isset($location_id) ? $location_id : Cache::session_get('frontend', 'tab');
		$selected = isset($tab) && $tab ? $tab : array_shift(array_keys($tabs));

		$this->location_id = $location_id;
		$this->tab_selected = $selected;

		foreach ($tabs as $key => &$_tab)
		{
			$_tab['location_id'] = $key;
		}

		$this->tabs_data = $tabs;
		if ($this->userSettings['preferences']['common']['template_set'] !== 'bootstrap')
		{
			$this->tabs = phpgwapi_jquery::tabview_generate($tabs, $this->tab_selected);
			$this->tabs_content = $this->generate_tabs_content($tabs);
		}

		//$this->tabs		= $this->phpgwapi_common->create_tabs($tabs, $selected);
		$this->menu = $this->create_menu($tabs, $selected);

		Cache::session_set('frontend', 'tab', $selected);

		// Get header state
		$this->header_state = Cache::session_get('frontend', 'header_state');
		$this->header_state['use_fellesdata'] = $use_fellesdata;
		$this->header_state['logo_path'] = $logo_path;
		$this->header_state['form_action'] = $tabs[$selected]['link'];

		$columns = 1;
		if (count($this->tabs_data) > 5)
		{
			$columns = 2;
		}
		if (count($this->tabs_data) > 8)
		{
			$columns = 3;
		}
		$this->header_state['tabs_data'] = array_chunk($this->tabs_data, ceil(count($this->tabs_data) / $columns));

		// Get navigation parameters
		$param_selected_location = Sanitizer::get_var('location'); // New location selected from locations list
		$param_selected_org_unit = Sanitizer::get_var('org_unit_id');  // New organisational unit selected from organisational units list
		$param_only_org_unit = Sanitizer::get_var('org_enhet_id');   // Frontend access from rental module regarding specific organisational unit
		//Refresh organisation list
		$refresh = Sanitizer::get_var('refresh', 'bool');

		$property_locations_update = false;

		/* If the user has selected an organisational unit or all units */
		if (isset($param_selected_org_unit) && $param_selected_org_unit && $param_selected_org_unit != 'none')
		{
			//Specify which unit(s)
			if ($param_selected_org_unit == 'all')
			{
				$org_unit_ids = $this->header_state['org_unit'];
			}
			else
			{
				if ($this->org_unit_in_selection($param_selected_org_unit, $this->header_state['org_unit']))
				{
					//Creating a temporary array holding the single organisational unit in query
					$org_unit_ids = array(
						array(
							"ORG_UNIT_ID" => $param_selected_org_unit
							//"ORG_NAME" => frontend_bofellesdata::get_instance()->get_organisational_unit_name($param_selected_org_unit),
							//"UNIT_ID" => $param_selected_org_unit
						)
					);
				}
				else
				{
					//If the organisational unit selected is not in list; do default 'all'
					$org_unit_ids = $this->header_state['org_unit'];
					$param_selected_org_unit = 'none';
				}
			}
			$this->header_state['selected_org_unit'] = $param_selected_org_unit;

			//Update locations according to organisational unit specification
			$property_locations = frontend_borental::get_property_locations($org_unit_ids, $this->header_state['org_unit']);

			$property_locations_update = true;
		}
		else if ($param_selected_org_unit == 'none')
		{
			$this->header_state['selected_org_unit'] = $param_selected_org_unit;
			$property_locations = array();
			$this->header_state['locations'] = $property_locations;
			$this->header_state['number_of_locations'] = count($property_locations);
		}

		/* If the user selects a organisational unit in rental module */
		else if (isset($param_only_org_unit) && $param_only_org_unit && $param_selected_org_unit != 'none')
		{
			//TODO: check permissions
			if ($use_fellesdata)
			{
				$name_and_result_number = frontend_bofellesdata::get_instance()->get_organisational_unit_info($param_only_org_unit);

				//Specify unit
				$org_unit_ids = array(
					array(
						"ORG_UNIT_ID" => $param_only_org_unit,
						"ORG_NAME" =>  $name_and_result_number['UNIT_NAME'] ? $name_and_result_number['UNIT_NAME'] : 'Org Name (Fellesdata utenfor rekkevidde)',
						"UNIT_ID" => $name_and_result_number['UNIT_NUMBER']
					)
				);

				//Update header state
				$this->header_state['org_unit'] = $org_unit_ids;
				$this->header_state['number_of_org_units'] = '1';
				//$this->header_state['selected_org_unit'] = $name_and_result_number['UNIT_NUMBER'];
				$this->header_state['selected_org_unit'] = $param_only_org_unit;

				//Update locations
				$property_locations = frontend_borental::get_property_locations($org_unit_ids, $this->header_state['org_unit']);
				$property_locations_update = true;

				$noframework = false; // In regular frames
				Cache::session_set('frontend', 'noframework', $noframework); // Store mode on session
				self::set_active_menu("frontend::{$selected}");
				$this->insert_links_on_header_state();
			}
		}
		/* No state, first visit after login, or refresh request */
		else if (!isset($this->header_state) || isset($refresh) || !isset($this->header_state['locations']))
		{
			if ($use_fellesdata)
			{
				//Specify organisational units
				$org_units = frontend_bofellesdata::get_instance()->get_result_units($this->userSettings['account_lid']);

				//Merge with delegation units
				$delegation_org_ids = frontend_bofrontend::get_delegations($this->userSettings['account_id']);
				if (count($delegation_org_ids) > 0)
				{
					$delegation_units = frontend_bofellesdata::get_instance()->populate_result_units($delegation_org_ids);
					$org_units = array_merge($org_units, $delegation_units);
				}

				//Update org units on header state
				$this->header_state['org_unit'] = $org_units;
				$this->header_state['number_of_org_units'] = count($org_units);
				$this->header_state['selected_org_unit'] = 'none';

				//Update locations
				//FIXME Sigurd 15. okt 2013: deselect 'all' on initial view
				//$property_locations = frontend_borental::get_property_locations($org_units, $this->header_state['org_unit']);
			}
			else if ($param_selected_org_unit != 'none')
			{
				//If no organisational database is in use: get rented properties based on username
				$usernames[] = $this->userSettings['account_lid'];
				$property_locations = frontend_borental::get_property_locations($usernames, $this->header_state['org_unit']);
			}

			$property_locations_update = true;
			$this->insert_links_on_header_state();
		}


		if ($property_locations_update)
		{
			if (isset($property_locations) && count($property_locations) > 0)
			{
				$this->header_state['selected_location'] = $property_locations[0]['location_code'];
				$param_selected_location = $property_locations[0]['location_code'];
			}
			else
			{
				$this->header_state['selected_location'] = '';
				$param_selected_location = '';
			}

			$this->header_state['locations'] = $property_locations;
			$this->header_state['number_of_locations'] = count((array)$property_locations);
			//FIXME
			$this->calculate_totals($property_locations);
		}


		/* If the user has selected a location or as a side-effect from selecting organisational unit */
		if ($param_selected_location)
		{
			$locs = $this->header_state['locations'];
			$exist = false;
			foreach ($locs as $loc)
			{
				if ($loc['location_code'] == $param_selected_location)
				{
					$exist = true;
				}
			}

			if ($exist)
			{
				$this->header_state['selected_location'] = $param_selected_location;

				$parties = frontend_borental::get_all_parties(array(), $this->header_state['selected_org_unit']);
				$totals = frontend_borental::get_total_cost_and_area($parties, $param_selected_location);

				$this->header_state['selected_total_price'] = number_format($totals['sum_total_price'], 2, ",", " ") . " " . lang('currency');
				$this->header_state['selected_total_area'] = number_format($totals['sum_total_area'], 2, ",", " ") . " " . lang('square_meters');

				Cache::session_set('frontend', 'header_state', $this->header_state);
			}
			else
			{
				//Set totals to 0
				$this->header_state['selected_location'] = $param_selected_location;
				$this->header_state['selected_total_price'] = lang('no_selection');
				$this->header_state['selected_total_area'] = lang('no_selection');
				Cache::session_set('frontend', 'header_state', $this->header_state);
			}

			Cache::session_clear('frontend', 'contract_state');
			Cache::session_clear('frontend', 'contract_state_in');
			Cache::session_clear('frontend', 'contract_state_ex');
		}
		/* Store the header state on the session */
		$bomessenger = CreateObject('messenger.bomessenger');
		$total_messages = $bomessenger->total_messages(" AND message_status = 'N'");
		if ($total_messages > 0)
		{
			$this->header_state['new_messages'] = "({$total_messages})";
		}
		else
		{
			$this->header_state['new_messages'] = lang('no_new_messages');
		}
		$this->header_state['total_messages'] = $total_messages;
		$this->header_state['profile'] = array(
			'name'	=> $this->userSettings['fullname'],
			'email' => $this->userSettings['preferences']['common']['email'],
			'cellphone' => $this->userSettings['preferences']['common']['cellphone']
		);

		Cache::session_set('frontend', 'header_state', $this->header_state);

		phpgwapi_css::getInstance()->add_external_file("frontend/templates/{$this->userSettings['preferences']['common']['template_set']}/css/base.css");
		Settings::getInstance()->update('flags', ['noframework' => true]);

		phpgwapi_js::getInstance()->validate_file('jquery', 'menu', 'frontend');
	}

	function get_tabs()
	{
		// Get tabs from location hierarchy
		// tabs [location identidier] = {label => ..., link => ...}
		$locations = frontend_bofrontend::get_sections();
		$tabs = array();
		foreach ($locations as $key => $entry)
		{
			$name = $entry['name'];
			$location = $entry['location'];

			if ($this->acl->check($location, ACL_READ, 'frontend'))
			{
				$location_id = $this->locations->get_id('frontend', $location);
				$tabs[$location_id] = array(
					'label' => lang($name),
					'link' => phpgw::link('/', array(
						'menuaction' => "frontend.ui{$name}.index",
						'location_id' => $location_id,
						'noframework' => $noframework
					))
				);
			}
			unset($location);
		}

		// this one is for generic entitysupport from the app 'property'
		$entity_frontend = isset($this->config->config_data['entity_frontend']) && $this->config->config_data['entity_frontend'] ? $this->config->config_data['entity_frontend'] : array();

		if ($entity_frontend)
		{
			$entity = CreateObject('property.soadmin_entity');
		}

		foreach ($entity_frontend as $location)
		{
			if ($this->acl->check($location, ACL_READ, 'property'))
			{
				$location_id = $this->locations->get_id('property', $location);
				$location_arr = explode('.', $location);

				$category = $entity->read_single_category($location_arr[2], $location_arr[3]);
				$tabs[$location_id] = array(
					'label' => $category['name'],
					'link' => phpgw::link('/', array(
						'menuaction' => "frontend.uientity.index",
						'location_id' => $location_id,
						'noframework' => $noframework
					))
				);
			}
		}

		$extra_tabs = Cache::session_get('frontend', 'extra_tabs');

		if (isset($extra_tabs) && $extra_tabs)
		{
			$tabs = $extra_tabs + $tabs;
		}

		Cache::session_clear('frontend', 'extra_tabs');

		return $tabs;
	}

	function generate_tabs_content($tabs)
	{
		$tabs_content = '';

		foreach ($tabs as $k => $v)
		{
			if ($k != $this->selected)
			{
				$tabs_content .= '<div id="' . $k . '"></div>';
			}
		}

		return $tabs_content;
	}

	/**
	 * Create Menu
	 *
	 * @param array   $tabs      With ($id,$tab) pairs
	 * @param integer $selection array key of selected tab
	 *
	 * @return string html snippet for creating menu in a modern browser
	 */
	public function create_menu($tabs, $selection)
	{
		/**
		 * Import the jQuery class
		 */
		phpgw::import_class('phpgwapi.jquery');

		$html = self::menu_generate($tabs, $selection);
		$output = <<<HTML
			<div class="menubar">
				{$html}
			</div>

HTML;
		return $output;
	}

	/**
	 * Create a menu "bar"
	 *
	 * @param array   $tabs      list of tabs as an array($id => $tab)
	 * @param integer $selection array key of selected tab
	 *
	 * @return string HTML output string
	 */
	public static function menu_generate($tabs, $selection)
	{

		phpgwapi_jquery::load_widget('menu');

		$output = <<<HTML

				<ul id="menu">
				<li><a href="#">moduler</a>
				<ul>
HTML;
		foreach ($tabs as $id => $tab)
		{
			$selected = $id == $selection ? ' class="selected"' : '';
			$label = $tab['label'];
			$_function = '';
			if (isset($tab['function']))
			{
				$_function = " onclick=\"javascript: {$tab['function']};\"";
			}

			if (!isset($tab['link']) && !isset($tab['function']))
			{
				$selected = $selected ? $selected : ' class="ui-state-disabled"';
				$output .= <<<HTML

						<li{$selected}><a><em>{$label}</em></a></li>
HTML;
			}
			else
			{
				$output .= <<<HTML

						<li{$selected}><a href="{$tab['link']}"{$_function}><em>{$label}</em></a></li>
HTML;
			}
		}
		$output .= <<<HTML

				</ul>
				</li>
				</ul>

HTML;
		return $output;
	}

	function insert_links_on_header_state()
	{
		$help_url = "";
		//check if help-document exists in VFS. If not, use manual.
		$help_in_vfs = true;
		$fileName = '/frontend/help/NO/helpdesk.index.pdf';
		$vfs = CreateObject('phpgwapi.vfs');
		$vfs->override_acl = 1;

		$file = array('string' => $fileName, RELATIVE_NONE);
		if ($vfs->file_exists($file))
		{
			$help_in_vfs = true;
		}

		if ($help_in_vfs)
		{
			$help_url = "javascript:openwindow('"
				. phpgw::link('/index.php', array(
					'menuaction' => 'frontend.uidocumentupload.read_helpfile_from_vfs',
					'app' => 'frontend'
				)) . "','700','600')";
		}
		else
		{
			$help_url = "javascript:openwindow('"
				. phpgw::link('/index.php', array(
					'menuaction' => 'manual.uimanual.help',
					'app' => $this->flags['currentapp'],
					'section' => isset($this->apps['manual']['section']) ? $this->apps['manual']['section'] : '',
					'referer' => Sanitizer::get_var('menuaction')
				)) . "','700','600')";
		}

		$contact_url = "javascript:openwindow('"
			. phpgw::link('/', array(
				'menuaction' => 'manual.uimanual.help',
				'app' => $this->flags['currentapp'],
				'section' => 'contact'
			)) . "','700','600')";

		$folder_url = "javascript:openwindow('"
			. phpgw::link('/', array(
				'menuaction' => 'manual.uimanual.help',
				'app' => $this->flags['currentapp'],
				'section' => 'folder'
			)) . "','700','600')";

		$name_of_user = $this->userSettings['firstname'] . " " . $this->userSettings['lastname'];

		if (count($this->userSettings['apps']) > 1)
		{
			$home_url = phpgw::link('/home/');
		}
		else
		{
			$home_url = phpgw::link('/', array(
				'menuaction' => 'frontend.uifrontend.index'
			));
		}

		$this->header_state['home_url'] = $home_url;
		$this->header_state['help_url'] = $help_url;
		$this->header_state['contact_url'] = $contact_url;
		$this->header_state['folder_url'] = $folder_url;
		$this->header_state['name_of_user'] = $name_of_user;
	}

	function calculate_totals($property_locations)
	{
		// Calculate
		$parties = frontend_borental::get_all_parties();

		$totals = frontend_borental::get_total_cost_and_area($parties);
		$this->header_state['total_price'] = number_format((float)$totals['sum_total_price'], 0, ",", " ") . " kr";
		$this->header_state['total_area'] = number_format((float)$totals['sum_total_area'], 0, ",", " ") . " kvm";
	}

	function location_in_selection($location_code, $property_locations)
	{
		foreach ($property_locations as $property_location)
		{
			if ($location_code == $property_location['location_code'])
			{
				return true;
			}
		}
		return false;
	}

	function org_unit_in_selection($unit_id, $org_units)
	{
		foreach ($org_units as $org_unit)
		{
			if ($unit_id == $org_unit['ORG_UNIT_ID'])
			{
				return true;
			}
		}
		return false;
	}

	public function get_org_enhet_id($result_unit_number, $org_units)
	{
		foreach ($org_units as $org_unit)
		{
			if ($result_unit_number == $org_unit['UNIT_ID'])
			{
				return $org_unit['ORG_UNIT_ID'];
			}
		}
		return false;
	}

	public function index()
	{
		//Forward to helpdesk
		$location_id = $this->locations->get_id('frontend', '.ticket');
		phpgw::redirect_link('/index.php', array(
			'menuaction' => 'frontend.uihelpdesk.index',
			'location_id' => $location_id
		));
	}

	public function objectimg()
	{
		Settings::getInstance()->update('flags', ['noheader' => true, 'nofooter' => true, 'xslt_app' => false]);

		$doc_type = $this->config->config_data['picture_building_cat'] ? $this->config->config_data['picture_building_cat'] : 'profilbilder';

		// Get object id from params or use 'dummy'
		$location_code = Sanitizer::get_var('loc_code') ? Sanitizer::get_var('loc_code') : 'dummy';

		$directory = "/property/document/{$location_code}/{$doc_type}";

		$vfs = CreateObject('phpgwapi.vfs');
		$vfs->override_acl = 1;

		$files = $vfs->ls(array(
			'orderby' => 'file_id',
			'mime_type'	=> 'image/jpeg',
			'string' => $directory,
			'relatives' => array(RELATIVE_NONE)
		));

		$vfs->override_acl = 0;

		$file = end($files);

		if (empty($file['file_id']))
		{
			phpgw::redirect_link('templates/base/images/missing_picture.png');
		}

		$mime_type = 'image/jpeg';
		if ($ls_array[0]['mime_type'])
		{
			$mime_type = $ls_array[0]['mime_type'];
		}

		header('Content-type: ' . $mime_type);

		$source = "{$this->serverSettings['files_dir']}{$file['directory']}/{$file['name']}";
		$thumbfile	 = "$source.thumb";

		if (is_file($thumbfile))
		{
			readfile($thumbfile);
		}
		else if (function_exists('imagejpeg'))
		{
			CreateObject('property.bofiles')->resize_image($source, $thumbfile, $thumb_size = 173);
			readfile($thumbfile);
		}
		else
		{
			ExecMethod('property.bofiles.get_file', $file['file_id']);
		}

		$this->phpgwapi_common->phpgw_exit();
	}
}
