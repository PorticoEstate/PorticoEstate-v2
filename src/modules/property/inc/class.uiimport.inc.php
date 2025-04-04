<?php

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
use App\Database\Db;

phpgw::import_class('phpgwapi.uicommon_jquery');
phpgw::import_class('phpgwapi.datetime');

class property_uiimport extends phpgwapi_uicommon_jquery
{

	var $public_functions = array(
		'index'		 => true,
		'components' => true
	);

	const DELIMITER	 = ";";
	const ENCLOSING	 = "'";

	// List of messages, warnings and errors to be displayed to the user after the import
	protected $messages		 = array();
	protected $warnings		 = array();
	protected $errors		 = array();
	// File system path to import folder on server
	protected $file;
	protected $district;
	protected $csvdata;
	protected $account;
	protected $conv_type;
	protected $location_id;
	protected $import_conversion;
	protected $steps		 = 0;
	protected $fields		 = array();
	protected $table;
	protected $debug;
	protected $db;
	protected $identificator;
	protected $filename_template;
	// Label on the import button. Changes as we step through the import process.
	protected $import_button_label;
	protected $download_template_button_label;
	protected $defalt_values;
	private $valid_tables	 = array();
	private $start_time;

	public function __construct()
	{
		parent::__construct();
		$this->start_time = phpgwapi_datetime::user_localtime();
		if (!$this->acl->check('run', Acl::READ, 'admin') && !$this->acl->check('admin', Acl::ADD, 'property'))
		{
			$this->flags['xslt_app'] = true;
			Settings::getInstance()->update('flags', ['xslt_app'=>true]);
			phpgw::no_access();
		}

		set_time_limit(10000);
		$this->account	 = (int)$this->userSettings['account_id'];
		$this->db		 = Db::getInstance();
		$this->table	 = Sanitizer::get_var('table');

		$this->valid_tables = array(
			'fm_zip_code'				 => array(
				'name'		 => 'fm_zip_code (' . lang('zip code') . ')',
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			),
			'fm_streetaddress'				 => array(
				'name'		 => 'fm_streetaddress (' . lang('street name') . ')',
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			),
			'fm_unspsc_code'				 => array(
				'name'		 => 'fm_unspsc_code (' . lang('unspsc code') . ')',
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			),
			'fm_external_project'			 => array(
				'name'		 => 'fm_external_project (' . lang('external project') . ')',
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			),
			'fm_eco_service'				 => array(
				'name'		 => 'fm_eco_service (' . lang('service') . ')',
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			),
			'fm_vendor'						 => array('name' => 'fm_vendor (' . lang('vendor') . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
			'fm_condition_survey'			 => array(
				'name'		 => 'fm_condition_survey (' . lang('condition survey') . ')',
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			),
			'fm_ecodimb'					 => array('name' => 'fm_ecodimb (' . lang('dimb') . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
			'fm_b_account'						 => array('name' => 'fm_b_account (' . lang('budget account') . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
			'fm_budget'						 => array('name' => 'fm_budget (' . lang('budget') . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
			'fm_org_unit'					 => array(
				'name'		 => 'fm_org_unit (' . lang('department') . ')',
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			),
			'fm_eco_periodization_outline'	 => array(
				'name'		 => 'fm_eco_periodization_outline (' . lang('periodization outline') . ')',
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			),
			'fm_eco_periodization'			 => array(
				'name'		 => 'fm_eco_periodization (' . lang('periodization') . ')',
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			),
			'fm_ecodimd'					 => array('name' => 'fm_ecodimd', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
			'phpgw_categories'				 => array('name'		 => 'phpgw_categories (' . lang('categories') . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
			'fm_building_part'	 => array('name'		 => 'fm_building_part (' . 'Bygningsdel' . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
		);

		$location_types = execMethod('property.soadmin_location.select_location_type');

		$lang_category = lang('category');
		foreach ($location_types as $location_type)
		{
			$this->valid_tables["fm_location{$location_type['id']}"]			 = array(
				'name'		 => "fm_location{$location_type['id']} ({$location_type['name']})",
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			);
			$this->valid_tables["fm_location{$location_type['id']}_category"]	 = array(
				'name'		 => "fm_location{$location_type['id']}_category ({$location_type['name']} {$lang_category})",
				'permission' => ACL_READ | ACL_ADD | ACL_EDIT
			);
		}

		if (isset($this->userSettings['apps']['booking']))
		{
			$booking_tables = array(
				'bb_building'				 => array('name'		 => 'bb_building (' . 'Ressursgruppe' . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
				'bb_resource'				 => array('name'		 => 'bb_resource (' . 'Ressurser' . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
				'bb_building_resource'		 => array('name'		 => 'bb_building_resource (' . 'Ressurskobling' . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
				'bb_organization'		 => array('name'		 => 'bb_organization (' . 'Organisasjoner' . ')', 'permission' => ACL_READ | ACL_ADD | ACL_EDIT),
			);

			$this->valid_tables = array_merge($this->valid_tables, $booking_tables);
		}

		if ($this->table && !in_array($this->table, array_keys($this->valid_tables)))
		{
			throw new Exception("Not a valid table: {$this->table}");
		}

		$this->flags['menu_selection']	 = 'admin::property::import';
		Settings::getInstance()->update('flags', ['menu_selection'=> $this->flags['menu_selection']]);
	}

	function query()
	{
		
	}

	/**
	 * Public method. 
	 * 
	 * @return unknown_type
	 */
	public function index()
	{
		// Set the submit button label to its initial state
		$this->import_button_label				 = "Start import";
		$this->download_template_button_label	 = 'Download template';

		$check_method		 = 0;
		$get_identificator	 = false;
		if ($this->conv_type	 = Sanitizer::get_var('conv_type'))
		{
			$check_method++;
			$get_identificator = true;
		}
		if ($this->location_id = Sanitizer::get_var('location_id', 'int'))
		{
			$check_method++;
			$get_identificator = true;
		}

		if ($table = Sanitizer::get_var('table'))
		{
			$check_method++;
			$get_identificator = true;
		}

		if ($check_method > 1)
		{
			Cache::session_set('property', 'import_message', 'choose only one target!');
			phpgw::redirect_link('/index.php', array('menuaction' => 'property.uiimport.index'));
		}


		Cache::session_set('property', 'import_settings', $_POST);

		$download_template	 = Sanitizer::get_var('download_template');
		$this->debug		 = Sanitizer::get_var('debug', 'bool');

		if ($download_template)
		{
			$this->get_template($this->location_id);
		}
		else
		{
			$this->phpgwapi_common->phpgw_header(true);
		}

		// If the parameter 'importsubmit' exist (submit button in import form), set path
		if (Sanitizer::get_var("importsubmit"))
		{
			if (phpgw::is_repost() && !Sanitizer::get_var('debug', 'bool'))
			{
				Cache::session_set('property', 'import_message', 'Hmm... looks like a repost!');
				phpgw::redirect_link('/index.php', array('menuaction' => 'property.uiimport.index'));
			}


			$start = date("G:i:s", $this->start_time);
			echo "<h3>Import started at: {$start}</h3>";
			echo "<ul>";

			if ($this->conv_type)
			{
				if (preg_match('/\.\./', $this->conv_type))
				{
					throw new Exception("Not a valid file: {$this->conv_type}");
				}

				$file = PHPGW_SERVER_ROOT . "/property/inc/import/{$this->userSettings['domain']}/{$this->conv_type}";

				if (is_file($file))
				{
					require_once $file;
				}
			}
			else
			{
				require_once PHPGW_SERVER_ROOT . "/property/inc/import/import_update_generic.php";
			}


			$this->import_conversion = new import_conversion($this->location_id, $this->debug);

			// Get the path for user input or use a default path

			$files = array();
			if (isset($_FILES['file']['tmp_name']) && $_FILES['file']['tmp_name'])
			{
				$files[] = array(
					'name'	 => $_FILES['file']['tmp_name'],
					'type'	 => $_FILES['file']['type']
				);
			}
			else
			{
				$path	 = Sanitizer::get_var('path', 'string');
				$files	 = $this->get_files($path);
			}

			if (!$files)
			{
				Cache::session_set('property', 'import_message', 'Ingen filer er valgt');
				phpgw::redirect_link('/index.php', array('menuaction' => 'property.uiimport.index'));
			}

			foreach ($files as $file)
			{
				$valid_type = false;
				switch ($file['type'])
				{
					case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
					case 'application/vnd.oasis.opendocument.spreadsheet':
					case 'application/vnd.ms-excel':
						$this->csvdata	 = $this->getexceldata($file['name'], $get_identificator);
						$valid_type		 = true;
						break;
					case 'text/csv':
					case 'text/comma-separated-values':
						$this->csvdata	 = $this->getcsvdata($file['name'], $get_identificator);
						$valid_type		 = true;
						break;
					default:
						throw new Exception("Not a valid filetype: {$file['type']}");
				}

				if ($valid_type)
				{
					$result			 = $this->import();
					$this->messages	 = array_merge($this->messages, $this->import_conversion->messages);
					$this->warnings	 = array_merge($this->warnings, $this->import_conversion->warnings);
					$this->errors	 = array_merge($this->errors, $this->import_conversion->errors);
					$this->csvdata	 = array();
					echo '<li class="info">Import: finished step ' . $result . '</li>';
				}
			}


			echo "</ul>";
			$end_time	 = phpgwapi_datetime::user_localtime();
			$difference	 = ($end_time - $this->start_time) / 60;
			$end		 = date("G:i:s", $end_time);
			echo "<h3>Import ended at: {$end}. Import lasted {$difference} minutes.";

			if ($this->errors)
			{
				echo "<ul>";
				foreach ($this->errors as $error)
				{
					echo '<li class="error">Error: ' . $error . '</li>';
				}

				echo "</ul>";
			}

			if ($this->warnings)
			{
				echo "<ul>";
				foreach ($this->warnings as $warning)
				{
					echo '<li class="warning">Warning: ' . $warning . '</li>';
				}
				echo "</ul>";
			}

			if ($this->messages)
			{
				echo "<ul>";

				foreach ($this->messages as $message)
				{
					echo '<li class="info">Message: ' . $message . '</li>';
				}
				echo "</ul>";
			}
			echo '<a href="' . phpgw::link('/home/') . '">Home</a>';
			echo '</br><a href="' . phpgw::link('/index.php', array('menuaction' => 'property.uiimport.index')) . '">Import</a>';
		}
		else
		{
			$import_settings = Cache::session_get('property', 'import_settings');
			$import_message	 = Cache::session_get('property', 'import_message');

			Cache::session_clear('property', 'import_message');
			$conv_list = $this->get_import_conv($import_settings['conv_type']);

			$conv_option = '<option value="">' . lang('none selected') . '</option>' . "\n";
			foreach ($conv_list as $conv)
			{
				$selected = '';
				if ($conv['selected'])
				{
					$selected = 'selected =  "selected"';
				}

				$conv_option .= <<<HTML
					<option value='{$conv['id']}'{$selected}>{$conv['name']}</option>
HTML;
			}

			$tables = $this->valid_tables;

			ksort($tables);

			$table_option = '<option value="">' . lang('none selected') . '</option>' . "\n";
			foreach ($tables as $table => $table_info)
			{
				$selected		 = $import_settings['table'] == $table ? 'selected =  "selected"' : '';
				$table_option	 .= <<<HTML
					<option value='{$table}'{$selected}>{$table_info['name']}::{$table_info['permission']}</option>
HTML;
			}

			$entity			 = CreateObject('property.soadmin_entity');
			$entity_list	 = $entity->read(array('allrows' => true));
			$category_option = '<option value="">' . lang('none selected') . '</option>' . "\n";
			foreach ($entity_list as $entry)
			{
				$category_list = $entity->read_category_tree2($entry['id']);

				foreach ($category_list as $category)
				{
					$selected		 = $import_settings['location_id'] == $category['location_id'] ? 'selected =  "selected"' : '';
					$category_option .= <<<HTML
						<option value="{$category['location_id']}"{$selected}>{$category['name']}</option>
HTML;
				}
			}

			$home	 = phpgw::link('/home/');
			$action	 = phpgw::link('/index.php', array('menuaction' => 'property.uiimport.index'));

			$debug_checked	 = isset($import_settings['debug']) && $import_settings['debug'] ? 'checked =  "checked"' : '';
			$html			 = <<<HTML
				<h1><i class="fas fa-save"></i> Importer ( MsExcel / CSV )</h1>
				<div id="messageHolder">{$import_message}</div>
				<form action="{$action}" method="post" enctype="multipart/form-data" class="pure-form pure-form-aligned">
					<fieldset>
						<div class="pure-control-group">
							<label for="file">Choose file:</label>
							<input type="file" name="file" id="file" title = 'Single file'class="pure-input-1-2"/>
						</p>
						<div class="pure-control-group">
							<label for="path">Local path:</label>
							<input type="text" name="path" id="path" value = '{$import_settings['path']}' title = 'Alle filer i katalogen' class="pure-input-1-2"/>
						</div>
						<div class="pure-control-group">
							<label for="conv_type">Choose conversion:</label>
							<select name="conv_type" id="conv_type" class="pure-input-1-2">
								{$conv_option}
							</select>
						</div>
						<div class="pure-control-group">
							<label for="table">Choose Table:</label>
							<select name="table" id="table" class="pure-input-1-2">
								{$table_option}
							</select>
						</div>
						<div class="pure-control-group">
							<label for="category">Choose category:</label>
							<select name="location_id" id="category" class="pure-input-1-2">
								{$category_option}
							</select>
						</div>

						<div class="pure-control-group">
							<label for="debug">Debug:</label>
							<input type="checkbox" name="debug" id="debug" {$debug_checked} value ='1' />
						</div>
						<div class="pure-controls">
							<input type="submit" name="download_template" value="{$this->download_template_button_label}" class="pure-button pure-button-primary"/>
							<input type="submit" name="importsubmit" value="{$this->import_button_label}" class="pure-button pure-button-primary"/>
						</div>
		 			</fieldset>
				</form>
				<br><a href='$home'>Home</a>
HTML;
			echo $html;
		}
	}

	/**
	 * Import Facilit data to Portico Estate's rental module
	 * The function assumes CSV files have been uploaded to a location on the server reachable by the
	 * web server user.  The CSV files must correspond to the table names from Facilit, as exported
	 * from Access. Field should be enclosed in single quotes and separated by comma.  The CSV files
	 * must contain the column headers on the first line.
	 * 
	 * @return unknown_type
	 */
	public function import()
	{
		$this->steps++;

		/* Import logic:
			 * 
			 * 1. Do step logic if the session variable is not set
			 * 2. Set step result on session
			 * 3. Set label for import button
			 * 4. Log messages for this step
			 *  
			 */

		$this->import_data();
		$this->log_messages($this->steps);
		return $this->steps;
	}

	protected function get_template($location_id = 0)
	{
		if ($this->debug)
		{
			$this->phpgwapi_common->phpgw_header(true);
		}

		$_identificator	 = array();
		$data			 = array();
		$_fields		 = array();
		if (!$location_id && $this->table)
		{
			$_permission = $this->valid_tables[$this->table]['permission'];

			if (!($_permission & ACL_READ))
			{
				throw new Exception("No READ-right for {$this->table}");
			}

			$metadata = $this->db->metadata($this->table);

			/**
			 * Remove id-column from fm_location-tables
			 */
			if (!empty($metadata['location_code']) && $metadata['loc1']->primary_key && !$metadata['id']->primary_key)
			{
				unset($metadata['id']);
			}

			foreach ($metadata as $field => $info)
			{
				$_fields[$field] = true;
			}

			$sql = "SELECT * FROM {$this->table}";
			$this->db->query($sql, __LINE__, __FILE__);

			while ($this->db->next_record())
			{
				$data[] = $this->db->Record;
			}
		}
		else if ($location_id && !$category = execMethod('property.soadmin_entity.get_single_category', $location_id))
		{
			throw new Exception("Not a valid location for {$location_id}");
		}
		else if ($location_id)
		{
			$system_location = $this->locations->get_name($location_id);
			$_identificator	 = array(
				'identificator' => "location::{$system_location['appname']}::{$system_location['location']}"
			);

			$filename = "fm_entity_{$category['entity_id']}_{$category['id']}";

			$entity_id	 = $category['entity_id'];
			$cat_id		 = $category['id'];

			if ($category['is_eav'])
			{
				$this->table = 'fm_bim_item';

				$metadata = $this->db->metadata($this->table);

				foreach ($metadata as $field => $info)
				{
					if ($field == 'json_representation' || $field == 'xml_representation' || $field == 'guid')
					{
						continue;
					}
					$_fields[$field] = true;
				}

				$custom		 = createObject('property.custom_fields');
				$attributes	 = $custom->find2($location_id, 0, '', 'ASC', 'attrib_sort', true, true);


				$sql = "SELECT * FROM {$this->table} WHERE location_id = $location_id ORDER BY id ASC";
				$this->db->query($sql, __LINE__, __FILE__);

				while ($this->db->next_record())
				{
					$_row_data = array();
					foreach ($_fields as $_field => $dummy)
					{
						$_row_data[$_field] = $this->db->f($_field, true);
					}

					$jsondata = json_decode($this->db->f('json_representation'), true);

					foreach ($attributes as $attribute)
					{
						$_row_data[$attribute['column_name']] = $this->db->stripslashes($jsondata[$attribute['column_name']]);
					}

					$data[] = $_row_data;
				}

				foreach ($attributes as $attribute)
				{
					$_fields[$attribute['column_name']] = true;
				}
			}
			else
			{
				$this->table = "fm_entity_{$category['entity_id']}_{$category['id']}";
				$metadata	 = $this->db->metadata($this->table);
				foreach ($metadata as $field => $info)
				{
					$_fields[$field] = true;
				}

				$sql = "SELECT * FROM {$this->table} ORDER BY id ASC";
				$this->db->query($sql, __LINE__, __FILE__);

				while ($this->db->next_record())
				{
					$data[] = $this->db->Record;
				}
			}
		}
		if (!$_identificator && $this->table)
		{
			$_identificator	 = array(
				'identificator' => "table::{$this->table}"
			);
			$filename		 = $this->table;
		}
		else if (!$_identificator && $this->conv_type)
		{
			$_identificator = array(
				'identificator' => "conversion::{$this->conv_type}"
			);

			if (preg_match('/\.\./', $this->conv_type))
			{
				throw new Exception("Not a valid file: {$this->conv_type}");
			}

			$file = PHPGW_SERVER_ROOT . "/property/inc/import/{$this->userSettings['domain']}/{$this->conv_type}";

			if (is_file($file))
			{
				require_once $file;
			}
			$_import_conversion	 = new import_conversion(0, false, true);
			$fields				 = $_import_conversion->fields;
			$filename			 = $_import_conversion->filename_template;
		}

		$fields = $fields ? $fields : array_keys($_fields);

		if (Sanitizer::get_var('debug', 'bool'))
		{
			_debug_array($fields);
		}
		else
		{
			$bocommon = CreateObject('property.bocommon');
			$bocommon->download($data, $fields, $fields, array(), $_identificator, $filename);
			$this->phpgwapi_common->phpgw_exit();
		}
	}

	protected function import_data()
	{
		if (!$this->identificator)
		{
			throw new Exception("Missing identificator in dataset");
		}
		$identificator_arr = explode("::", $this->identificator);
		switch ($identificator_arr[0])
		{
			case 'location':
				if (!$this->location_id)
				{
					throw new Exception("No valid location selected for : {$identificator_arr[2]}");
				}
				else if ($this->locations->get_id($identificator_arr[1], $identificator_arr[2]) != $this->location_id)
				{
					throw new Exception("No valid location selected for : {$identificator_arr[2]}");
				}
				break;
			case 'table':
				if (!$this->table)
				{
					throw new Exception("Table not selected");
				}
				else if ($identificator_arr[1] != $this->table)
				{
					throw new Exception("Not the intended target? got: {$identificator_arr[1]} , expected: {$this->table}");
				}
				break;
			case 'conversion':
				if (!$this->conv_type)
				{
					throw new Exception("Conversion not selected");
				}
				else if ($identificator_arr[1] != $this->conv_type)
				{
					throw new Exception("Not the intended target? got: {$identificator_arr[1]} , expected: {$this->conv_type}");
				}
				break;
			default:
				throw new Exception("No valid location");
		}

		$metadata = array();
		if ($this->table && $this->fields)
		{
			$_permission = $this->valid_tables[$this->table]['permission'];

			if (!($_permission & ACL_ADD))
			{
				throw new Exception("No ADD-right for {$this->table}");
			}

			$metadata = $this->db->metadata($this->table);

			if (Sanitizer::get_var('debug', 'bool'))
			{
				_debug_array($metadata);
			}

			foreach ($this->fields as $field)
			{
				if ($field && !isset($metadata[$field]))
				{
					$this->messages[] = "Feltet '{$field}' finnes ikke i tabellen '{$this->table}'";
				}
			}

			$this->import_conversion->set_table($this->table);
			$this->import_conversion->set_metadata($metadata);
		}

		if ($this->fields)
		{
			$found_field = false;

			foreach ($this->fields as $field)
			{
				if ($field && !$found_field)
				{
					$found_field = true;
				}
			}

			if (!$found_field)
			{
				throw new Exception("Felter er ikke definert");
			}
			$this->import_conversion->fields = $this->fields;
		}


		$datalines = $this->csvdata;

		$ok	 = true;
		$_ok = false;
		$this->db->transaction_begin();

		//Do your magic...
		foreach ($datalines as $data)
		{
			try
			{
				$_ok = $this->import_conversion->add($data);
			}
			catch (Exception $ex)
			{
				$this->errors[] = "Import of data failed. (" . (phpgwapi_datetime::user_localtime() - $this->start_time) . " seconds)";
				$this->errors[] = $ex->getMessage();
				$this->db->transaction_abort();
				return false;
			}

			if (!$_ok)
			{
				$ok = false;
			}
		}

		if ($ok)
		{
			$this->messages[] = "Imported data. (" . (phpgwapi_datetime::user_localtime() - $this->start_time) . " seconds)";
			if ($this->debug)
			{
				$this->db->transaction_abort();
				$this->messages[] = "Dry Run: transaction abortet";
			}
			else
			{
				if ($this->table)
				{
					$this->import_conversion->update_sequence();
				}
				$this->db->transaction_commit();
			}
			return true;
		}
		else
		{
			$this->errors[] = "Import of data failed. (" . (phpgwapi_datetime::user_localtime() - $this->start_time) . " seconds)";
			$this->db->transaction_abort();
			return false;
		}

		$this->messages	 = array_merge($this->messages, $this->import_conversion->messages);
		$this->warnings	 = array_merge($this->warnings, $this->import_conversion->warnings);
		$this->errors	 = array_merge($this->errors, $this->import_conversion->errors);
	}

	private function _xml2array($xmlObject, $out = array())
	{
		foreach ((array)$xmlObject as $index => $node)
		{
			$out[$index] = (is_object($node) || is_array($node)) ? $this->_xml2array($node) : $node;
		}

		return $out;
	}

	protected function getxmldata($path, $get_identificator = true)
	{
		$xml = simplexml_load_file($path);
		$out = $this->_xml2array($xml);

		return $out;
	}

	protected function getcsvdata($path, $get_identificator = true)
	{
		// Open the csv file
		$handle = fopen($path, "r");

		if ($get_identificator)
		{
			$_identificator_arr	 = $this->getcsv($handle);
			$this->identificator = $_identificator_arr[0];
		}

		// Read the first line to get the headers out of the way
		$this->fields = $this->getcsv($handle);

		$result = array();

		while (($data = $this->getcsv($handle)) !== false)
		{
			$result[] = $data;
		}

		fclose($handle);

		$this->messages[]	 = "Read '{$path}' file in " . (phpgwapi_datetime::user_localtime() - $this->start_time) . " seconds";
		$this->messages[]	 = "'{$path}' contained " . count($result) . " lines";

		return $result;
	}

	protected function getexceldata($path, $get_identificator = false)
	{

		$inputFileType	 = \PhpOffice\PhpSpreadsheet\IOFactory::identify($path);
		$reader			 = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
		$reader->setReadDataOnly(true);
		$spreadsheet	 = $reader->load($path);

		$spreadsheet->setActiveSheetIndex(0);

		$result = array();

		$calculate_highest_column_from = $get_identificator ? 2 : 1;
		$highestColumn		 = $spreadsheet->getActiveSheet()->getHighestColumn($calculate_highest_column_from);
		$highestColumnIndex	 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
		$rows				 = (int)$spreadsheet->getActiveSheet()->getHighestRow();

		$start = $get_identificator ? 3 : 1; // Read the first line to get the headers out of the way

		if ($get_identificator)
		{
			$this->identificator = $spreadsheet->getActiveSheet()->getCell([1, 1])->getCalculatedValue();
			for ($j = 1; $j <= $highestColumnIndex; $j++)
			{
				$this->fields[] = $spreadsheet->getActiveSheet()->getCell([$j, 2])->getCalculatedValue();
			}
		}
		else
		{
			for ($j = 1; $j <= $highestColumnIndex; $j++)
			{
				$this->fields[] = $spreadsheet->getActiveSheet()->getCell([$j, 1])->getCalculatedValue();
			}
		}

		$rows = $rows ? $rows : 1;
		for ($row = $start; $row <= $rows; $row++)
		{
			$_result = array();

			for ($j = 1; $j <= $highestColumnIndex; $j++)
			{
				$_result[] = $spreadsheet->getActiveSheet()->getCell([$j, $row])->getCalculatedValue();
			}

			$result[] = $_result;
		}

		$this->messages[]	 = "Read '{$path}' file in " . (phpgwapi_datetime::user_localtime() - $this->start_time) . " seconds";
		$this->messages[]	 = "'{$path}' contained " . count($result) . " lines";

		return $result;
	}

	/**
	 * Read the next line from the given file handle and parse it to CSV according to the rules set up
	 * in the class constants DELIMITER and ENCLOSING.  Returns FALSE like getcsv on EOF.
	 * 
	 * @param file-handle $handle
	 * @return array of values from the parsed csv line
	 */
	protected function getcsv($handle)
	{
		return fgetcsv($handle, 1000, self::DELIMITER, self::ENCLOSING, "\\");
	}

	private function log_messages($step)
	{
		//	sort($this->errors);
		//	sort($this->warnings);
		//	sort($this->messages);

		$msgs = array_merge(
			array('----------------Errors--------------------'),
			$this->errors,
			array('---------------Warnings-------------------'),
			$this->warnings,
			array(
				'---------------Messages-------------------'
			),
			$this->messages
		);

		$path = $this->serverSettings['temp_dir'];
		if (is_dir($path . '/logs') || mkdir($path . '/logs'))
		{
			file_put_contents("$path/logs/$step.log", implode(PHP_EOL, $msgs));
		}
	}

	protected function get_import_conv($selected = '')
	{
		$conv_list = array();
		$dir_handle	 = opendir(PHPGW_SERVER_ROOT . "/property/inc/import/{$this->userSettings['domain']}");

		if (!$dir_handle)
		{
			return $conv_list;
		}

		$myfilearray = array();
		while ($file = readdir($dir_handle))
		{
			if ((substr($file, 0, 1) != '.') && is_file(PHPGW_SERVER_ROOT . "/property/inc/import/{$this->userSettings['domain']}/{$file}"))
			{
				$myfilearray[] = $file;
			}
		}
		closedir($dir_handle);
		sort($myfilearray);

		for ($i = 0; $i < count($myfilearray); $i++)
		{
			$fname = preg_replace('/_/', ' ', $myfilearray[$i]);

			$conv_list[] = array(
				'id'		 => $myfilearray[$i],
				'name'		 => $fname,
				'selected'	 => $myfilearray[$i] == $selected ? 1 : 0
			);
		}

		return $conv_list;
	}

	protected function get_files($dirname)
	{
		// prevent path traversal
		if (preg_match('/\./', $dirname) || !is_dir($dirname))
		{
			return array();
		}

		$mime_magic = createObject('phpgwapi.mime_magic');

		$file_list	 = array();
		$dir		 = new DirectoryIterator($dirname);
		if (is_object($dir))
		{
			foreach ($dir as $file)
			{
				if ($file->isDot() || !$file->isFile() || !$file->isReadable())
				//						|| strcasecmp( end( explode( ".", $file->getPathname() ) ), 'xls' ) != 0 )
				//						|| strcasecmp( end( explode( ".", $file->getPathname() ) ), 'csv' ) != 0 ))
				{
					continue;
				}

				$file_name	 = $file->__toString();
				$file_list[] = array(
					'name'	 => (string)"{$dirname}/{$file_name}",
					'type'	 => $mime_magic->filename2mime($file_name)
				);
			}
		}

		return $file_list;
	}

	/**
	 * Public method. 
	 * 
	 * @return unknown_type
	 */
	public function components()
	{
		// Set the submit button label to its initial state
		$this->import_button_label = "Start import";

		$check_method		 = 0;
		$get_identificator	 = false;
		/* if ($this->conv_type = Sanitizer::get_var('conv_type'))
			  {
			  $check_method ++;
			  $get_identificator = true;
			  }

			  if ($this->location_id = Sanitizer::get_var('location_id', 'int'))
			  {
			  $check_method ++;
			  $get_identificator = true;
			  }

			  if ($table = Sanitizer::get_var('table'))
			  {
			  $check_method ++;
			  $get_identificator = true;
			  } */

		if ($check_method > 1)
		{
			Cache::session_set('property', 'import_message', 'choose only one target!');
			phpgw::redirect_link('/index.php', array('menuaction' => 'property.uiimport.components'));
		}


		Cache::session_set('property', 'import_settings', $_POST);

		/* $download_template = Sanitizer::get_var('download_template');

			  if ($download_template)
			  {
			  $this->get_template($this->location_id);
			  } */

		// If the parameter 'importsubmit' exist (submit button in import form), set path
		if (Sanitizer::get_var("importsubmit"))
		{
			if (phpgw::is_repost() && !Sanitizer::get_var('debug', 'bool'))
			{
				Cache::session_set('property', 'import_message', 'Hmm... looks like a repost!');
				phpgw::redirect_link('/index.php', array('menuaction' => 'property.uiimport.components'));
			}

			$start = date("G:i:s", $this->start_time);
			echo "<h3>Import started at: {$start}</h3>";
			echo "<ul>";

			/* if ($this->conv_type)
				  {
				  if (preg_match('/\.\./', $this->conv_type))
				  {
				  throw new Exception("Not a valid file: {$this->conv_type}");
				  }

				  $file = PHPGW_SERVER_ROOT . "/property/inc/import/{$this->userSettings['domain']}/{$this->conv_type}";

				  if (is_file($file))
				  {
				  require_once $file;
				  }
				  }
				  else
				  {
				  require_once PHPGW_SERVER_ROOT . "/property/inc/import/import_update_generic.php";
				  } */


			$this->debug = Sanitizer::get_var('debug', 'bool');
			//$this->import_conversion = new import_conversion($this->location_id, $this->debug);
			// Get the path for user input or use a default path

			$files = array();
			if (isset($_FILES['file']['tmp_name']) && $_FILES['file']['tmp_name'])
			{
				$files[] = array(
					'name'	 => $_FILES['file']['tmp_name'],
					'type'	 => $_FILES['file']['type']
				);
			}
			else
			{
				$path	 = Sanitizer::get_var('path', 'string');
				$files	 = $this->get_files($path);
			}

			if (!$files)
			{
				Cache::session_set('property', 'import_message', 'Ingen filer er valgt');
				phpgw::redirect_link('/index.php', array('menuaction' => 'property.uiimport.components'));
			}

			$entity_categories_in_xml = array();
			foreach ($files as $file)
			{
				$valid_type = true;
				/* switch ($file['type'])
					  {
					  case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
					  case 'application/vnd.oasis.opendocument.spreadsheet':
					  case 'application/vnd.ms-excel':
					  $this->csvdata = $this->getexceldata($file['name'], $get_identificator);
					  $valid_type = true;
					  break;
					  case 'text/csv':
					  case 'text/comma-separated-values':
					  $this->csvdata = $this->getcsvdata($file['name'], $get_identificator);
					  $valid_type = true;
					  break;
					  default:
					  throw new Exception("Not a valid filetype: {$file['type']}");
					  } */

				$result = $this->getxmldata($file['name'], $get_identificator);

				$postnrdelkode	 = $result['Prosjekter']['ProsjektNS']['Postnrplan']['PostnrdelKoder']['PostnrdelKode'];
				$entities_name	 = array();
				foreach ($postnrdelkode as $items)
				{
					if ($items['PostnrdelKoder']['PostnrdelKode']['Kode'])
					{
						$entities_name[$items['PostnrdelKoder']['PostnrdelKode']['Kode']] = array(
							'name' => $items['PostnrdelKoder']['PostnrdelKode']['Kode'] . ' - ' . $items['PostnrdelKoder']['PostnrdelKode']['Navn']
						);
					}
					else
					{
						foreach ($items['PostnrdelKoder']['PostnrdelKode'] as $item)
						{
							$entities_name[$item['Kode']] = array('name' => $item['Kode'] . ' - ' . $item['Navn']);
						}
					}
				}

				$posts = $result['Prosjekter']['ProsjektNS']['Prosjektdata']['Post'];
				foreach ($posts as $post)
				{
					$buildingpart											 = $post['Postnrdeler']['Postnrdel'][1]['Kode'];
					$entity_categories_in_xml[$buildingpart]['name']		 = $entities_name[$buildingpart]['name'];
					$entity_categories_in_xml[$buildingpart]['components'][] = array(
						array('name' => 'benevnelse', 'value' => trim($post['Egenskaper']['Egenskap']['Verdi'])),
						array('name' => 'beskrivelse', 'value' => trim($post['Tekst']['Uformatert']))
					);

					//$buildingpart_in_xml[$post['Postnrdeler']['Postnrdel'][1]['Kode']] = $post['Postnrdeler']['Postnrdel'][1]['Kode'];
				}

				//echo '<li class="info">Import: finished step ' . print_r($buildingpart) . '</li>';
			}

			require_once PHPGW_SERVER_ROOT . "/property/inc/import/import_update_components.php";

			$import_components	 = new import_components();
			$entity_categories	 = $import_components->get_entity_categories();

			$buildingpart_out_table = array();
			foreach ($entity_categories_in_xml as $k => $v)
			{
				if (!array_key_exists((string)$k, $entity_categories))
				{
					$buildingpart_parent		 = substr($k, 0, strlen($k) - 1);
					$buildingpart_out_table[$k]	 = array(
						'parent' => $entity_categories[$buildingpart_parent],
						'name'	 => $v['name']
					);
				}
				else
				{
					$entity_categories_in_xml[$k]['cat_id']		 = $entity_categories[$k]['id'];
					$entity_categories_in_xml[$k]['entity_id']	 = $entity_categories[$k]['entity_id'];
				}
			}

			if (count($buildingpart_out_table))
			{
				$buildingpart_processed = $import_components->add_entity_categories($buildingpart_out_table);

				if (count($buildingpart_processed['added']))
				{
					echo 'Entities added: <br>';
					foreach ($buildingpart_processed['added'] as $k => $v)
					{
						$entity_categories_in_xml[$k]['cat_id']		 = $v['id'];
						$entity_categories_in_xml[$k]['entity_id']	 = $v['entity_id'];
						echo $v['name'] . '<br>';
					}
				}

				if (count($buildingpart_processed['not_added']))
				{
					echo '<br>Entities not added: <br>';
					foreach ($buildingpart_processed['not_added'] as $k => $v)
					{
						unset($entity_categories_in_xml[$k]);
						echo $v['name'] . '<br>';
					}
				}
			}

			$components_not_added = $import_components->add_bim_item($entity_categories_in_xml);
			if (count($components_not_added))
			{
				echo '<br>Components not added: <br>';
				foreach ($components_not_added as $k => $v)
				{
					echo $k . ' => not added: ' . $v . '<br>';
				}
			}

			//print_r($entity_categories_in_xml);

			echo "</ul>";
			$end_time	 = phpgwapi_datetime::user_localtime();
			$difference	 = ($end_time - $this->start_time) / 60;
			$end		 = date("G:i:s", $end_time);
			echo "<h3>Import ended at: {$end}. Import lasted {$difference} minutes.";

			if ($this->errors)
			{
				echo "<ul>";
				foreach ($this->errors as $error)
				{
					echo '<li class="error">Error: ' . $error . '</li>';
				}

				echo "</ul>";
			}

			if ($this->warnings)
			{
				echo "<ul>";
				foreach ($this->warnings as $warning)
				{
					echo '<li class="warning">Warning: ' . $warning . '</li>';
				}
				echo "</ul>";
			}

			if ($this->messages)
			{
				echo "<ul>";

				foreach ($this->messages as $message)
				{
					echo '<li class="info">Message: ' . $message . '</li>';
				}
				echo "</ul>";
			}
			echo '<a href="' . phpgw::link('/home/') . '">Home</a>';
			echo '</br><a href="' . phpgw::link('/index.php', array('menuaction' => 'property.uiimport.components')) . '">Import</a>';
		}
		else
		{
			$import_settings = Cache::session_get('property', 'import_settings');
			$import_message	 = Cache::session_get('property', 'import_message');

			Cache::session_clear('property', 'import_message');


			$home	 = phpgw::link('/home/');
			$action	 = phpgw::link('/index.php', array('menuaction' => 'property.uiimport.components'));

			//$debug_checked = isset($import_settings['debug']) && $import_settings['debug'] ? 'checked =  "checked"' : '';
			$html = <<<HTML
				<h1><i class="fas fa-save"></i> Importer ( MsExcel / CSV )</h1>
				<div id="messageHolder">{$import_message}</div>
				<form action="{$action}" method="post" enctype="multipart/form-data" class="pure-form pure-form-aligned">
					<fieldset>
						<div class="pure-control-group">
							<label for="file">Choose file:</label>
							<input type="file" name="file" id="file" title = 'Single file'/>
						</div>
						<div class="pure-control-group">
							<label for="path">Local path:</label>
							<input type="text" name="path" id="path" value = '{$import_settings['path']}' title = 'Alle filer i katalogen'/>
						</div>
						<div class="pure-control-group">
							<label for="debug">Debug:</label>
							<input type="checkbox" name="debug" id="debug" {$debug_checked} value ='1' />
						</div>
						<p>
							<input type="submit" name="importsubmit" value="{$this->import_button_label}"  />
						</p>
		 			</fieldset>
				</form>
				<br><a href='$home'>Home</a>
HTML;
			echo $html;
		}
	}
}
