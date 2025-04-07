<?php

/**
 * phpGroupWare
 *
 * @author Erik Holm-Larsen <erik.holm-larsen@bouvet.no>
 * @author Torstein Vadla <torstein.vadla@bouvet.no>
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2012 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @package phpgwapi
 * @subpackage utilities
 * @version $Id: class.uicommon.inc.php 11988 2014-05-23 13:26:30Z sigurdne $
 */

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Log;


phpgw::import_class('phpgwapi.jquery');

abstract class phpgwapi_uicommon_jquery
{

	const UI_SESSION_FLASH = 'flash_msgs';
	public static $flash_msgs = array();
	public static $tmpl_search_path;

	protected
		$filesArray, $url_prefix, $acl, $locations, $phpgwapi_common;
	public $dateFormat;
	public $type_of_user;
	public $flags;
	public $serverSettings;
	public $userSettings;
	public $apps;

	public function __construct($currentapp = '', $yui = '')
	{
		$this->phpgwapi_common = new \phpgwapi_common();

		$this->flags = Settings::getInstance()->get('flags');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->apps = Settings::getInstance()->get('apps');


		$yui = isset($yui) && $yui == 'yui3' ? 'yui3' : 'yahoo';
		$currentapp = $currentapp ? $currentapp : $this->flags['currentapp'];

		if (preg_match("/(Trident\/(\d{2,}|7|8|9)(.*)rv:(\d{2,}))|(MSIE\ (\d{2,}|8|9)(.*)Tablet\ PC)|(Trident\/(\d{2,}|7|8|9))/", $_SERVER["HTTP_USER_AGENT"]))
		{
			if ($this->userSettings['preferences']['common']['rteditor'])
			{
				$this->userSettings['preferences']['common']['rteditor'] = 'ckeditor';
			}
		}

		self::get_tmpl_search_path();

		if ($yui == 'yui3')
		{
			self::add_javascript('phpgwapi', 'yui3', 'yui/yui-min.js');
			self::add_javascript('phpgwapi', $yui, 'common.js');
		}

		$this->url_prefix = str_replace('_', '.', get_class($this));

		$this->dateFormat = $this->userSettings['preferences']['common']['dateformat'];

		$this->acl = Acl::getInstance();
		$this->locations = new Locations();

		Settings::getInstance()->update('flags', ['app_header' => lang($currentapp)]);

		phpgwapi_jquery::load_widget('core');
		phpgwapi_jquery::load_widget('contextMenu');
		self::add_javascript('phpgwapi', "jquery", 'common.js', false, array('combine' => true));


		self::add_javascript('phpgwapi', 'DataTables2', 'datatables.min.js', false, array('combine' => true));
		self::add_javascript('phpgwapi', 'DataTables2', 'plugins/dataTables.inputPaging.js', false, array('combine' => true));
		self::add_javascript('phpgwapi', 'jquery', 'editable/jquery.jeditable.min.js', false, array('combine' => true));
		self::add_javascript('phpgwapi', 'jquery', 'editable/jquery.dataTables.editable.js', false, array('combine' => true));
		phpgwapi_css::getInstance()->add_external_file('phpgwapi/js/DataTables2/datatables.min.css');
		phpgwapi_css::getInstance()->add_external_file('phpgwapi/js/DataTables2/plugins/dataTables.inputPaging.min.css');

		//pop up script
		self::add_javascript('phpgwapi', 'tinybox2', 'packed.js', false, array('combine' => true));
		phpgwapi_css::getInstance()->add_external_file('phpgwapi/js/tinybox2/style.css');

		if (\Sanitizer::get_var('nonavbar'))
		{
			Settings::getInstance()->update('flags', ['nonavbar' => true, 'noframework' => true]);
			//		Settings::getInstance()->get('flags')['noframework'] = true;
			//	Settings::getInstance()->get('flags')['headonly']=true;
		}
	}

	private static function get_tmpl_search_path()
	{
		if (self::$tmpl_search_path)
		{
			return self::$tmpl_search_path;
		}
		else
		{
			$tmpl_search_path = array();
			//				array_push($tmpl_search_path, PHPGW_SERVER_ROOT . '/booking/templates/base');
			array_push($tmpl_search_path, PHPGW_SERVER_ROOT . '/phpgwapi/templates/base');
			array_push($tmpl_search_path, PHPGW_SERVER_ROOT . '/phpgwapi/templates/' . Settings::getInstance()->get('server')['template_set']);
			array_push($tmpl_search_path, PHPGW_SERVER_ROOT . '/' . Settings::getInstance()->get('flags')['currentapp'] . '/templates/base');
			array_push($tmpl_search_path, PHPGW_SERVER_ROOT . '/' . Settings::getInstance()->get('flags')['currentapp'] . '/templates/' . Settings::getInstance()->get('server')['template_set']);
			self::$tmpl_search_path = $tmpl_search_path;
		}
		return $tmpl_search_path;
	}

	public static function get_ui_session_key()
	{
		return self::current_app() . '_uicommon';
	}

	protected static function current_app()
	{
		return Settings::getInstance()->get('flags')['currentapp'];
	}

	protected static function restore_flash_msgs()
	{
		if (($flash_msgs = self::session_get(self::UI_SESSION_FLASH)))
		{
			if (is_array($flash_msgs))
			{
				self::$flash_msgs = $flash_msgs;
				self::session_set(self::UI_SESSION_FLASH, array());
				return true;
			}
		}

		self::$flash_msgs = array();
		return false;
	}

	protected static function store_flash_msgs()
	{
		return self::session_set(self::UI_SESSION_FLASH, self::$flash_msgs);
	}

	protected static function reset_flash_msgs()
	{
		self::$flash_msgs = array();
		self::store_flash_msgs();
	}

	/**
	 * Get the CSS search path.
	 *
	 * @return array
	 */
	private static function get_css_search_path()
	{
		$css_search_path = array();
		// Modify the search path as needed
		array_push($css_search_path, 'phpgwapi/templates/base/css');
		array_push($css_search_path, 'phpgwapi/templates/' . Settings::getInstance()->get('server')['template_set'] . '/css');
		array_push($css_search_path, Settings::getInstance()->get('flags')['currentapp'] . '/templates/base/css');
		array_push($css_search_path, Settings::getInstance()->get('flags')['currentapp'] . '/templates/' . Settings::getInstance()->get('server')['template_set'] . '/css');

		return $css_search_path;
	}

	/**
	 * Search for an external CSS file using the template search path.
	 *
	 * @param string $filename The name of the CSS file to include.
	 * @param bool $required @throws Exception if the CSS file is not found in the search path.
	 */
	public static function add_external_css_with_search($filename, $required = false)
	{
		$css_search_path = self::get_css_search_path();

		foreach (array_reverse($css_search_path) as $path)
		{
			$fullPath = $path . '/' . $filename;
			if (file_exists(PHPGW_SERVER_ROOT . '/' . $fullPath))
			{
				phpgwapi_css::getInstance()->add_external_file($fullPath);
				return;
			}
		}
		if ($required)
		{
			throw new Exception("CSS file $filename not found in search path: " . print_r($css_search_path, true));
		}
	}


	protected static function session_set($key, $data)
	{
		return Cache::session_set(self::get_ui_session_key(), $key, $data);
	}

	protected static function session_get($key)
	{
		return Cache::session_get(self::get_ui_session_key(), $key);
	}

	/**
	 * Provides a private session cache setter per ui class.
	 */
	protected function ui_session_set($key, $data)
	{
		return $this->session_set(get_class($this) . '_' . $key, $data);
	}

	/**
	 * Provides a private session cache getter per ui class .
	 */
	protected function ui_session_get($key)
	{
		return $this->session_get(get_class($this) . '_' . $key);
	}

	protected function generate_secret($length = 16)
	{
		return bin2hex(random_bytes($length));
	}

	public function add_js_event($event, $js)
	{
		phpgwapi_js::getInstance()->add_event($event, $js);
	}

	public function add_js_load_event($js)
	{
		$this->add_js_event('load', $js);
	}

	static function get_link_base()
	{
		$base = '/index.php';

		switch (Settings::getInstance()->get('flags')['currentapp'])
		{
			case 'bookingfrontend':
				$base = '/bookingfrontend/';
				break;
			case 'activitycalendarfrontend':
				$base = '/activitycalendarfrontend/';
				break;
			case 'eventplannerfrontend':
				$base = '/eventplannerfrontend/';
				break;
			default:
				$base = '/index.php';
				break;
		}
		return $base;
	}

	public static function link($data, $redirect = false, $external = false, $force_backend = false)
	{
		$base = self::get_link_base();
		return phpgw::link($base, $data, $redirect, $external, $force_backend);
	}

	public static function redirect($link_data)
	{
		$base = self::get_link_base();
		phpgw::redirect_link($base, $link_data);
	}

	public function flash($msg, $type = 'success')
	{
		self::$flash_msgs[$msg] = $type == 'success';
	}

	public function flash_form_errors($errors)
	{
		foreach ($errors as $field => $msg)
		{
			self::$flash_msgs[$msg] = false;
		}
	}

	public static function message_set($messages = array())
	{
		if (isset($messages['error']) && is_array($messages['error']))
		{
			foreach ($messages['error'] as $key => $entry)
			{
				Cache::message_set($entry['msg'], 'error');
			}
			unset($entry);
		}
		if (isset($messages['message']) && is_array($messages['message']))
		{
			foreach ($messages['message'] as $key => $entry)
			{
				Cache::message_set($entry['msg'], 'message');
			}
		}
	}

	public function add_stylesheet($path)
	{
		phpgwapi_css::getInstance()->add_external_file($path);
	}


	/**
	 *
	 * @param string $app
	 * @param string $pkg will always look within template set, then fallback to $pkg
	 * @param string $name name of the javascript file to include
	 * @param bool $end_of_page
	 * @param array $config
	 * @return bool
	 */

	public static function add_javascript($app, $pkg, $name, $end_of_page = false, $config = array())
	{
		if ($end_of_page === "text/javascript")
		{
			$bt = debug_backtrace();
			$log = new Log();
			$log->error(array(
				'text'	=> 'js::%1 Called from file: %2 line: %3',
				'p1'	=> $bt[0]['function'],
				'p2'	=> $bt[0]['file'],
				'p3'	=> $bt[0]['line'],
				'line'	=> __LINE__,
				'file'	=> __FILE__
			));
			unset($bt);
		}
		return phpgwapi_js::getInstance()->validate_file($pkg, str_replace('.js', '', $name), $app, $end_of_page, $config);
	}

	public static function set_active_menu($item)
	{
		Settings::getInstance()->update('flags', ['menu_selection' => $item]);
	}

	/**
	 * A more flexible version of xslttemplate.add_file
	 */
	public static function add_template_file($tmpl)
	{
		$tmpl_search_path = self::get_tmpl_search_path();

		if (is_array($tmpl))
		{
			foreach ($tmpl as $t)
			{
				self::add_template_file($t);
			}
			return;
		}

		foreach (array_reverse($tmpl_search_path) as $path)
		{
			$filename = $path . '/' . $tmpl . '.xsl';
			if (file_exists($filename))
			{
				phpgwapi_xslttemplates::getInstance()->add_file($tmpl, $path);
				return;
			}
		}
		throw new Exception("Template $tmpl not found in search path:" . print_r($tmpl_search_path, true));
	}

	public static function render_template($output)
	{
		$phpgwapi_common = new \phpgwapi_common();
		$phpgwapi_common->phpgw_header(true);
		if (self::$flash_msgs)
		{
			$msgbox_data = $phpgwapi_common->msgbox_data(self::$flash_msgs);
			$msgbox_data = $phpgwapi_common->msgbox($msgbox_data);
			foreach ($msgbox_data as &$message)
			{
				echo "<div class='{$message['msgbox_class']}'>";
				echo $message['msgbox_text'];
				echo '</div>';
			}
		}
		echo htmlspecialchars_decode($output);
		$phpgwapi_common->phpgw_exit();
	}

	/**
	 * Creates an array of translated strings.
	 */
	function lang_array()
	{
		$keys = func_get_args();
		foreach ($keys as &$key)
		{
			$key = lang($key);
		}
		return $keys;
	}

	public static function add_jquery_translation(&$data)
	{
		self::add_template_file('jquery_phpgw_i18n');
		$previous = lang('prev');
		$next = lang('next');
		$first = lang('first');
		$last = lang('last');
		$showing_items = lang('showing items');
		$of = lang('of');
		$to = lang('to');
		$shows_from = lang('shows from');
		$of_total = lang('of total');
		$sort_asc = lang(': activate to sort column ascending');
		$sort_desc = lang(': activate to sort column descending');

		if (isset(Settings::getInstance()->get('user')['preferences']['common']['maxmatchs']) && Settings::getInstance()->get('user')['preferences']['common']['maxmatchs'] > 0)
		{
			$rows_per_page = Settings::getInstance()->get('user')['preferences']['common']['maxmatchs'];
		}
		else
		{
			$rows_per_page = 10;
		}
		$lengthmenu = array();
		for ($i = 1; $i < 4; $i++)
		{
			$lengthmenu[0][] = $i * $rows_per_page;
			$lengthmenu[1][] = $i * $rows_per_page;
		}

		if (isset($data['datatable']['allrows']) && $data['datatable']['allrows'])
		{
			$lengthmenu[0][] = -1;
			$lengthmenu[1][] = lang('all');
		}
		$data['jquery_phpgw_i18n'] = array(
			'datatable' => array(
				'emptyTable' => json_encode(lang("No data available in table")),
				'info' => json_encode(lang("Showing _START_ to _END_ of _TOTAL_ entries")),
				'infoEmpty' => json_encode(lang("Showing 0 to 0 of 0 entries")),
				'infoFiltered' => json_encode(lang("(filtered from _MAX_ total entries)")),
				'infoPostFix' => json_encode(""),
				'thousands' => json_encode(","),
				'lengthMenu' => json_encode(lang("Show _MENU_ entries")),
				'loadingRecords' => json_encode(lang("Loading...")),
				'processing' => json_encode(lang("Processing...")),
				//					'processing' => json_encode('<i class="fa fa-spinner fa-spin fa-3x fa-fw"></i><span class="sr-only">' . lang("Processing...") . '</span> '),
				'search' => json_encode(lang('search')),
				'zeroRecords' => json_encode(lang("No matching records found")),
				'paginate' => json_encode(array(
					'first' => $first,
					'last' => $last,
					'next' => $next,
					'previous' => $previous
				)),
				'aria' => json_encode(array(
					'sortAscending' => $sort_asc,
					'sortDescending' => $sort_desc
				)),
				'select' => json_encode(array('rows' => array('0' => '', '_' => '%d ' . lang('rows selected'))))
			),
			'lengthmenu' => array('_' => json_encode($lengthmenu)),
			'lengthmenu_allrows' => array('_' => json_encode(array(-1, lang('all')))),
			'csv_download' => array('_' => json_encode(
				array(
					'show_button' => empty(Settings::getInstance()->get('user')['preferences']['common']['csv_download']) ? false : true,
					'title'			=> lang('download visible data')
				)
			))

		);
		//			_debug_array($data['jquery_phpgw_i18n']);die();
	}

	public function add_template_helpers()
	{
		self::add_template_file('helpers');
	}

	public static function render_template_xsl($files, $data, $xsl_rootdir = '', $base = 'data')
	{

		$flags = Settings::getInstance()->get('flags');
		$flags['xslt_app'] = true;
		Settings::getInstance()->update('flags', ['xslt_app' => true]);
		$phpgwapi_common = new \phpgwapi_common();

		if ($xsl_rootdir)
		{
			if (!in_array($xsl_rootdir, self::$tmpl_search_path))
			{
				array_push(self::$tmpl_search_path, $xsl_rootdir);
			}
		}

		if (self::$flash_msgs)
		{
			$data['msgbox_data'] = $phpgwapi_common->msgbox(self::$flash_msgs);
		}
		else
		{
			self::add_template_file('msgbox');
		}

		self::reset_flash_msgs();

		self::add_jquery_translation($data);
		$data['webserver_url'] = Settings::getInstance()->get('server')['webserver_url'] . PHPGW_MODULES_PATH;

		if (preg_match("/(Trident\/(\d{2,}|7|8|9)(.*)rv:(\d{2,}))|(MSIE\ (\d{2,}|8|9)(.*)Tablet\ PC)|(Trident\/(\d{2,}|7|8|9))/", $_SERVER["HTTP_USER_AGENT"]))
		{
			$data['browser_support'] = 'legacy';
		}
		else
		{
			$data['browser_support'] = 'modern';
		}

		if (\Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'json')
		{
			//				echo json_encode($data);
			$phpgwapi_common->phpgw_exit();
		}

		$output = \Sanitizer::get_var('output', 'string', 'REQUEST', 'html');
		phpgwapi_xslttemplates::getInstance()->set_output($output);
		self::add_template_file($files);

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array($base => $data));
	}

	// Add link key to a result array
	// Add link key to a result array
	public function _add_links(&$value, $key, $data)
	{
		$unset = 0;
		// FIXME: Fugly workaround
		// I cannot figure out why this variable isn't set, but it is needed
		// by the ->link() method, otherwise we wind up in the phpgroupware
		// errorhandler which does lot of weird things and breaks the output
		if (!isset(Settings::getInstance()->get('server')['webserver_url']))
		{
			Settings::getInstance()->get('server')['webserver_url'] = "/";
			$unset = 1;
		}

		if (is_array($data))
		{
			$link_array = $data;
			$link_array['id'] = $value['id'];
		}
		else
		{
			$link_array = array('menuaction' => $data, 'id' => $value['id']);
		}

		$value['link'] = self::link($link_array);

		// FIXME: Fugly workaround
		// I kid you not my friend. There is something very wonky going on
		// in phpgroupware which I cannot figure out.
		// If this variable isn't unset() (if it wasn't set before that is)
		// then it will contain extra slashes and break URLs
		if ($unset)
		{
			unset(Settings::getInstance()->get('server')['webserver_url']);
		}
	}

	// Build a YUI result style array
	public function yui_results($results)
	{
		if (!$results)
		{
			$results['total_records'] = 0;
			$result['results'] = array();
		}

		$num_rows = isset(Settings::getInstance()->get('user')['preferences']['common']['maxmatchs']) && Settings::getInstance()->get('user')['preferences']['common']['maxmatchs'] ? (int)Settings::getInstance()->get('user')['preferences']['common']['maxmatchs'] : 15;

		return array(
			'ResultSet' => array(
				'totalResultsAvailable' => $results['total_records'],
				'totalRecords' => $results['total_records'], // temeporary
				'recordsReturned' => count($results['results']),
				'pageSize' => $num_rows,
				'startIndex' => $results['start'],
				'sortKey' => $results['sort'],
				'sortDir' => $results['dir'],
				'Result' => $results['results'],
				'actions' => $results['actions']
			)
		);
	}

	// Build a jquery result style array
	public function jquery_results($result = array())
	{
		if (!$result)
		{
			$result['recordsTotal'] = 0;
			$result['recordsFiltered'] = 0;
			$result['data'] = array();
		}

		$result['recordsTotal'] = $result['total_records'];
		$result['recordsFiltered'] = $result['recordsTotal'];
		$result['data'] = (array)$result['results'];
		unset($result['results']);
		unset($result['total_records']);

		return $result;
	}

	/**
	 * Initiate rich text editor for selected targets
	 * @param array $targets
	 */
	public static function rich_text_editor($targets)
	{
		if (empty(Settings::getInstance()->get('user')['preferences']['common']['rteditor']))
		{
			return;
		}
		if (!is_array($targets))
		{
			$targets = array($targets);
		}
		switch (Settings::getInstance()->get('user')['preferences']['common']['rteditor'])
		{
			case 'ckeditor':
				foreach ($targets as $target)
				{
					phpgwapi_jquery::init_ckeditor($target);
					//						phpgwapi_jquery::init_summernote($target);
				}
				break;
			case 'summernote':
				foreach ($targets as $target)
				{
					phpgwapi_jquery::init_summernote($target);
				}
				break;
			case 'quill':
				foreach ($targets as $target)
				{
					phpgwapi_jquery::init_quill($target);
				}
				break;

			default:
				break;
		}
	}

	/**
	 * Initiate rich text editor for selected targets
	 * @param array $targets
	 */
	public function use_yui_editor($targets)
	{
		$this->rich_text_editor($targets);
	}

	public function render($template, $local_variables = array())
	{
		foreach ($local_variables as $name => $value)
		{
			$$name = $value;
		}

		ob_start();
		foreach (array_reverse(self::$tmpl_search_path) as $path)
		{
			$filename = $path . '/' . $template;
			if (file_exists($filename))
			{
				include($filename);
				break;
			}
		}
		$output = ob_get_contents();
		ob_end_clean();
		self::render_template($output);
	}

	/**
	 * Method for JSON queries.
	 *
	 * @return YUI result
	 */
	public abstract function query();

	/**
	 * Generate javascript for the extra column definitions for a partial list
	 *
	 * @param $array_name the name of the javascript variable that contains the column definitions
	 * @param $extra_cols the list of extra columns to set
	 * @return string javascript
	 */
	public static function get_extra_column_defs($array_name, $extra_cols = array())
	{
		$result = "";

		foreach ($extra_cols as $col)
		{
			$literal = '{';
			$literal .= 'key: "' . $col['key'] . '",';
			$literal .= 'label: "' . $col['label'] . '"';
			if (isset($col['formatter']))
			{
				$literal .= ',formatter: ' . $col['formatter'];
			}
			if (isset($col['parser']))
			{
				$literal .= ',parser: ' . $col['parser'];
			}
			$literal .= '}';

			if ($col["index"])
			{
				$result .= "{$array_name}.splice(" . $col["index"] . ", 0," . $literal . ");";
			}
			else
			{
				$result .= "{$array_name}.push($literal);";
			}
		}

		return $result;
	}

	/**
	 * Generate javascript definitions for any editor widgets set on columns for
	 * a partial list.
	 *
	 * @param $array_name the name of the javascript variable that contains the column definitions
	 * @param $editors the list of editors, keyed by column key
	 * @return string javascript
	 */
	public static function get_column_editors($array_name, $editors = array())
	{
		$result = "for (var i in {$array_name}) {\n";
		$result .= "	switch ({$array_name}[i].key) {\n";
		foreach ($editors as $field => $editor)
		{
			$result .= "		case '{$field}':\n";
			$result .= "			{$array_name}[i].editor = {$editor};\n";
			$result .= "			break;\n";
		}
		$result .= " }\n";
		$result .= "}";

		return $result;
	}

	/**
	 * Returns a html-formatted error message if one is defined in the
	 * list of validation errors on the object we're given.  If no
	 * error is defined, an empty string is returned.
	 *
	 * @param $object the object to display errors for
	 * @param $field the name of the attribute to display errors for
	 * @return string a html formatted error message
	 */
	public static function get_field_error($object, $field)
	{
		if (isset($object))
		{
			$errors = $object->get_validation_errors();

			if ($errors[$field])
			{
				return '<label class="error" for="' . $field . '">' . $errors[$field] . '</label>';
			}
			return '';
		}
	}

	public static function get_messages($messages, $message_type)
	{
		$output = '';
		if (is_array($messages) && count($messages) > 0) // Array of messages
		{
			$output = "<div class=\"{$message_type}\">";
			foreach ($messages as $message)
			{
				$output .= "<p class=\"message\">{$message}</p>";
			}
			$output .= "</div>";
		}
		else if ($messages)
		{
			$output = "<div class=\"{$message_type}\"><p class=\"message\">{$messages}</p></div>";
		}
		return $output;
	}

	/**
	 * Returns a html-formatted error message to display on top of the page.  If
	 * no error is defined, an empty string is returned.
	 *
	 * @param $error the error to display
	 * @return string a html formatted error message
	 */
	public static function get_page_error($errors)
	{
		return self::get_messages($errors, 'error');
	}

	/**
	 * Returns a html-formatted error message to display on top of the page.  If
	 * no error is defined, an empty string is returned.
	 *
	 * @param $error the error to display
	 * @return string a html formatted error message
	 */
	public static function get_page_warning($warnings)
	{
		return self::get_messages($warnings, 'warning');
	}

	/**
	 * Returns a html-formatted info message to display on top of the page.  If
	 * no message is defined, an empty string is returned.
	 *
	 * @param $message the message to display
	 * @return string a html formatted info message
	 */
	public static function get_page_message($messages)
	{
		return self::get_messages($messages, 'info');
	}

	/**
	 * Download xls, csv or similar file representation of a data table
	 */
	public function download()
	{
		$list = $this->query();
		$list = $list['ResultSet']['Result'];

		$keys = array();

		if (count($list[0]) > 0)
		{
			foreach ($list[0] as $key => $value)
			{
				if (!is_array($value))
				{
					array_push($keys, $key);
				}
			}
		}

		// Remove newlines from output
		$count = count($list);
		for ($i = 0; $i < $count; $i++)
		{
			foreach ($list[$i] as $key => &$data)
			{
				$data = str_replace(array("\n", "\r\n", "<br>"), '', $data);
			}
		}

		// Use keys as headings
		$headings = array();
		$count_keys = count($keys);
		for ($j = 0; $j < $count_keys; $j++)
		{
			array_push($headings, lang($keys[$j]));
		}

		$property_common = CreateObject('property.bocommon');
		$property_common->download($list, $keys, $headings);
	}

	/**
	 * Returns a human-readable string from a lower case and underscored word by replacing underscores
	 * with a space, and by upper-casing the initial characters.
	 *
	 * @param  string $lower_case_and_underscored_word String to make more readable.
	 *
	 * @return string Human-readable string.
	 */
	public static function humanize($lower_case_and_underscored_word)
	{
		if (substr($lower_case_and_underscored_word, -3) === '_id')
		{
			$lower_case_and_underscored_word = substr($lower_case_and_underscored_word, 0, -3);
		}

		return ucfirst(str_replace('_', ' ', $lower_case_and_underscored_word));
	}

	/**
	 * Retrieves an array of files from $_FILES
	 *
	 * @param  string $key  	A key
	 * @return array  		An associative array of files
	 */
	public function get_files_from_post($key = null)
	{
		if (!$this->filesArray)
		{
			$this->filesArray = self::convert_file_information($_FILES);
		}

		return is_null($key) ? $this->filesArray : (isset($this->filesArray[$key]) ? $this->filesArray[$key] : array());
	}

	public function toggle_show_showall()
	{
		if (isset($_SESSION['showall']) && !empty($_SESSION['showall']))
		{
			unset($_SESSION['showall']);
		}
		else
		{
			$_SESSION['showall'] = "1";
		}
		self::redirect(array('menuaction' => $this->url_prefix . '.index'));
	}

	public function toggle_show_inactive()
	{
		if (isset($_SESSION['showall']) && !empty($_SESSION['showall']))
		{
			unset($_SESSION['showall']);
		}
		else
		{
			$_SESSION['showall'] = "1";
		}
		self::redirect(array('menuaction' => $this->url_prefix . '.index'));
	}

	static protected function fix_php_files_array($data)
	{
		$fileKeys = array('error', 'name', 'size', 'tmp_name', 'type');
		$keys = array_keys($data);
		sort($keys);

		if ($fileKeys != $keys || !isset($data['name']) || !is_array($data['name']))
		{
			return $data;
		}

		$files = $data;
		foreach ($fileKeys as $k)
		{
			unset($files[$k]);
		}
		foreach (array_keys($data['name']) as $key)
		{
			$files[$key] = self::fix_php_files_array(array(
				'error' => $data['error'][$key],
				'name' => $data['name'][$key],
				'type' => $data['type'][$key],
				'tmp_name' => $data['tmp_name'][$key],
				'size' => $data['size'][$key],
			));
		}

		return $files;
	}

	/**
	 * It's safe to pass an already converted array, in which case this method just returns the original array unmodified.
	 *
	 * @param  array $taintedFiles An array representing uploaded file information
	 *
	 * @return array An array of re-ordered uploaded file information
	 */
	static public function convert_file_information(array $taintedFiles)
	{
		$files = array();
		foreach ($taintedFiles as $key => $data)
		{
			$files[$key] = self::fix_php_files_array($data);
		}

		return $files;
	}
}
