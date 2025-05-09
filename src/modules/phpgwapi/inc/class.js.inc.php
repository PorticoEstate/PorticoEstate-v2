<?php

/**
 * Javascript support class
 *
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @copyright Copyright (C) 2003-2008 Free Software Foundation, Inc http://www.fsf.org/
 * @license http://www.fsf.org/licenses/gpl.html GNU General Public License
 * @package phpgroupware
 * @subpackage phpgwapi
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
use App\modules\phpgwapi\services\Log;

/**
 * phpGroupWare javascript support class
 *
 * Don't instanstiate this class
 *
 * Simply use a reference to this class in your code like so
 *
 * $js =& phpgwapi_js::getInstance();
 *
 * This way a theme can see if this is a defined object and include the data,
 * while the is_object() wrapper prevents whiping out existing data held in
 * this instance variables, primarily the $files variable.
 *
 * Note: The package arguement is the subdirectory of js - all js should live in subdirectories
 *
 * @package phpgroupware
 * @subpackage phpgwapi
 * @category gui
 */
class phpgwapi_js
{

	/**
	 * @var array elements to be used for the window.on* events
	 */
	protected $win_events = array(
		'load'	 => array(),
		'unload' => array()
	);

	/**
	 * @var array list of validated files to be included in the head section of a page
	 */
	protected $files = array();

	/**
	 * @var array list of validated files to be included at the end of a page
	 */
	protected $end_files = array();

	/**
	 *
	 * @var array list of "external files to be included in the head section of a page
	 * Some times while using libs and such its not fesable to move js files to /app/js/package/
	 * because the js files are using relative paths
	 */
	protected $external_files;

	/**
	 *
	 * @var array list of "external files to be included at the end of a page
	 * Some times while using libs and such its not fesable to move js files to /app/js/package/
	 * because the js files are using relative paths
	 */
	protected $external_end_files;
	protected $webserver_url;
	protected $serverSettings;
	protected $cache_refresh_token = '';
	protected $log;


	/**
	 * @var phpgwapi_js reference to singleton instance
	 */
	private static $instance;


	/**
	 * Constructor
	 */
	private function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->validate_file('core', 'base', 'phpgwapi', false, array('combine' => true));
		$webserver_url		 = isset($this->serverSettings['webserver_url']) ? $this->serverSettings['webserver_url'] . PHPGW_MODULES_PATH : PHPGW_MODULES_PATH;
		$this->webserver_url = $webserver_url;
		$token	= isset($this->serverSettings['cache_refresh_token']) ? $this->serverSettings['cache_refresh_token'] : '0.0.0';
		$this->cache_refresh_token = '?n=' . $token;
		$this->log = new Log();
	}

	/**
	 * Gets the instance via lazy initialization (created on first usage)
	 */
	public static function getInstance(): phpgwapi_js
	{
		if (null === static::$instance)
		{
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Set a window.on?? event
	 *
	 * @param string $event the name of the event
	 * @param string $code the code to be called
	 */
	public function add_event($event, $code)
	{
		if (!isset($this->win_events[$event]))
		{
			$this->win_events[$event] = array();
		}
		$this->win_events[$event][] = $code;
	}

	/**
	 * Returns the javascript required for displaying a popup message box
	 *
	 * @param string $msg the message to be displayed to user
	 * @returns string the javascript to be used for displaying the message
	 */
	public function get_alert($msg)
	{
		return 'return alert("' . lang($msg) . '");';
	}

	/**
	 * Returns the javascript required for displaying a confirmation message box
	 *
	 * @param string $msg the message to be displayed to user
	 * @returns string the javascript to be used for displaying the message
	 */
	public function get_confirm($msg)
	{
		return 'return confirm("' . lang($msg) . '");';
	}

	/**
	 * Used for generating the list of external js files to be included in the head of a page
	 *
	 * NOTE: This method should only be called by the template class.
	 * The validation is done when the file is added so we don't have to worry now
	 *
	 * @returns string the html needed for importing the js into a page
	 */
	public function get_script_links($cache_refresh_token = '', $end_files = false)
	{
		if ($end_files)
		{
			$files			= $this->end_files;
			$external_files = $this->external_end_files;
		}
		else
		{
			$files			= $this->files;
			$external_files = $this->external_files;
		}


		$combine = true;

		if (!empty($this->serverSettings['no_jscombine']))
		{
			$combine = false;
		}

		if (ini_get('suhosin.get.max_value_length') && ini_get('suhosin.get.max_value_length') < 2000)
		{
			$combine = false;
			if (isset(Settings::getInstance()->get('user')['apps']['admin']))
			{
				$message = 'Speed could be gained from setting suhosin.get.max_value_length = 2000 in php.ini';
				\App\modules\phpgwapi\services\Cache::message_set($message, 'error');
			}
		}

		if ($combine)
		{
			$cachedir = "{$this->serverSettings['temp_dir']}/combine_cache";

			if (is_dir($this->serverSettings['temp_dir']) && !is_dir($cachedir))
			{
				mkdir($cachedir, 0770);
			}
		}

		$links	= "<!--JS Imports from phpGW javascript class -->\n";
		$_links = '';

		//            $links = "<!--JS Imports from phpGW javascript class -->\n";
		//			$_links = '';
		//            if (is_array($files) && count($files))
		//            {
		//                foreach ($files as $app => $packages)
		//                {
		//                    if (is_array($packages) && count($packages))
		//                    {
		//                        foreach ($packages as $pkg => $files)
		//                        {
		//                            if (is_array($files) && count($files))
		//                            {
		//                                foreach ($files as $file => $config)
		//                                {
		//                                    if($combine && !empty($config['combine']))
		//                                    {
		//                                        // Add file path to array and replace path separator with "--" for URL-friendlyness
		//                                        $jsfiles[] = str_replace('/', '--', "{$app}/js/{$pkg}/{$file}.js");
		//                                    }
		//                                    else
		//                                    {
		//                                        //echo "file: {$this->webserver_url}/{$app}/js/{$pkg}/{$file}.js <br>";
		//                                        $_links .= "<script ";
		//                                        if($config['type'] != 'text/javascript')
		//                                        {
		//                                            $_links .= "type=\"{$config['type']}\" ";
		//                                        }
		//                                        $_links .=  "src=\"{$this->webserver_url}/{$app}/js/{$pkg}/{$file}.js{$cache_refresh_token}\">"
		//                                            . "</script>\n";
		//                                    }
		//                                }
		//                                unset($config);
		//                            }
		//                        }
		//                    }
		//                }
		//            }
		$jsfiles = array();
		if (is_array($files) && count($files))
		{
			foreach ($files as $app => $packages)
			{
				if (is_array($packages) && count($packages))
				{
					foreach ($packages as $pkg => $items)
					{
						if (is_array($items) && count($items))
						{
							foreach ($items as $item => $value)
							{
								// Check if $value is an array representing a file's config or another directory
								if (is_array($value) && isset($value['type']))
								{
									// Direct file configuration found, process the file
									$filePath = $item; // As this is a direct file, item is the file
									$this->processFile($app, $pkg, $filePath, $value, $combine, $jsfiles, $_links);
								}
								else if (is_array($value))
								{
									// Another directory level, iterate over it
									foreach ($value as $file => $config)
									{
										if (is_array($config) && isset($config['type']))
										{
											// File configuration found in the nested level, process the file
											$filePath = "{$item}/{$file}"; // Adjust the path to include the new directory level
											$this->processFile($app, $pkg, $filePath, $config, $combine, $jsfiles, $_links);
										}
									}
								}
							}
						}
					}
				}
			}
		}

		if (!empty($external_files) && is_array($external_files))
		{
			foreach ($external_files as $file => $config)
			{
				if ($combine && !empty($config['combine']))
				{
					// Add file path to array and replace path separator with "--" for URL-friendlyness
					$jsfiles[] = str_replace('/', '--', ltrim($file, '/'));
				}
				else
				{
					$_links .= <<<HTML
						<script src="{$this->webserver_url}/{$file}{$cache_refresh_token}" >
						</script>
HTML;
				}
			}
		}

		if ($combine && $jsfiles)
		{
			$_cachedir = urlencode($cachedir);
			$_jsfiles  = implode(',', $jsfiles);
			$links	   .= '<script '
				. "src=\"{$this->webserver_url}/phpgwapi/inc/combine.php?cachedir={$_cachedir}&type=javascript&files={$_jsfiles}\">"
				. "</script>\n";
			unset($jsfiles);
			unset($_jsfiles);
		}
		$links .= $_links;

		return $links;
	}

	/**
	 * @deprecated
	 */
	public function get_body_attribs()
	{
		return '';
	}

	protected function processFile($app, $pkg, $file, $config, $combine, &$jsfiles, &$_links)
	{
		if ($combine && !empty($config['combine']))
		{
			$jsfiles[] = str_replace('/', '--', "{$app}/js/{$pkg}/{$file}.js");
		}
		else
		{
			$_links .= "<script ";
			if ($config['type'] != 'text/javascript')
			{
				$_links .= "type=\"{$config['type']}\" ";
			}
			$_links .= "src=\"{$this->webserver_url}/{$app}/js/{$pkg}/{$file}.js{$this->cache_refresh_token}\"></script>\n";
		}
	}

	/**
	 * Creates the javascript for handling window.on* events
	 *
	 * @returns string the attributes to be used
	 */
	public function get_win_on_events()
	{
		$ret_str = "\n// start phpGW javascript class imported window.on* event handlers\n";
		foreach ($this->win_events as $win_event => $actions)
		{
			if (is_array($actions) && count($actions))
			{
				if ($win_event == 'load')
				{
					//					$ret_str .= "document.addEventListener('DOMContentLoaded', function() {\n";
					$ret_str .= "window.addEventListener('load', function() {\n";
					foreach ($actions as $action)
					{
						$ret_str .= "\t$action\n";
					}
					$ret_str .= "})\n";
				}
				else
				{
					$ret_str .= "window.on{$win_event} = function()\n{\n";
					foreach ($actions as $action)
					{
						$ret_str .= "\t$action\n";
					}
					$ret_str .= "}\n";
				}
			}
		}
		$ret_str .= "\n// end phpGW javascript class imported window.on* event handlers\n\n";
		return $ret_str;
	}

	/**
	 * Sets an onLoad action for a page
	 *
	 * @param string javascript to be used
	 * @deprecated
	 */
	public function set_onload($code)
	{
		$this->win_events['load'][] = $code;
	}

	/**
	 * Sets an onUnload action for a page
	 *
	 * @param string javascript to be used
	 * @deprecated
	 */
	public function set_onunload($code)
	{
		$this->win_events['unload'][] = $code;
	}

	/**
	 * DO NOT USE - NOT SURE IF I AM GOING TO USE IT - ALSO IT NEEDS SOME CHANGES!!!!
	 * Used for removing a file or package of files to be included in the head section of a page
	 *
	 * @param string $app application to use
	 * @param string $package the name of the package to be removed
	 * @param string $file the name of a file in the package to be removed - if ommitted package is removed
	 */
	public function unset_script_link($app, $package, $file = False)
	{
		// THIS DOES NOTHING ATM :P
	}

	/**
	 * Checks to make sure a valid package and file name is provided
	 *
	 * @param string $package package to be included
	 * @param string $file file to be included - no ".js" on the end
	 * @param string $app application directory to search - default = phpgwapi
	 * @returns bool was the file found?
	 */
	public function validate_file($package, $file, $app = 'phpgwapi', $end_of_page = false, $config = array())
	{

		if ($end_of_page === "text/javascript")
		{
			$bt = debug_backtrace();
			$this->log->error(array(
				'text' => 'js::%1 Called from file: %2 line: %3',
				'p1'   => $bt[0]['function'],
				'p2'   => $bt[0]['file'],
				'p3'   => $bt[0]['line'],
				'line' => __LINE__,
				'file' => __FILE__
			));
			unset($bt);
		}

		if ($config === true)
		{
			$end_of_page = true;
		}


		if (empty($config['type']))
		{
			$config = array_merge((array)$config, array('type' => 'text/javascript'));
		}

		$template_set = $this->serverSettings['template_set'];
		//            if($file === "resource") {
		//                _debug_array(array(
		//                    "file" => $file,
		//                    "template_set" => $template_set,
		//                    "config" => $config,
		//                    "end_of_page" => $end_of_page ? 'T' : 'F',
		//                    (PHPGW_SERVER_ROOT . "/$app/js/$template_set/$file.js") => is_readable(PHPGW_SERVER_ROOT . "/$app/js/$template_set/$file.js") ? 'T' : 'F',
		//                    (PHPGW_SERVER_ROOT . "/$app/js/$template_set/dist/{$file}.bundle.js") => is_readable(PHPGW_SERVER_ROOT . "/$app/js/$template_set/dist/{$file}.bundle.js") ? 'T' : 'F',
		//                    (PHPGW_SERVER_ROOT . "/$app/js/$package/$file.js") => is_readable(PHPGW_SERVER_ROOT . "/$app/js/$package/$file.js") ? 'T' : 'F',
		//                ));die();
		//            }


		if (is_readable(PHPGW_SERVER_ROOT . "/$app/js/$template_set/$file.js"))
		{
			if ($end_of_page)
			{
				$this->end_files[$app][$template_set][$file] = $config;
			}
			else
			{
				$this->files[$app][$template_set][$file] = $config;
			}
			return True;
		} // New check for the bundled file in the /dist/js directory
		else if (is_readable(PHPGW_SERVER_ROOT . "/$app/js/$template_set/dist/{$file}.bundle.js"))
		{
			if ($end_of_page)
			{
				$this->end_files[$app][$template_set]['dist'][$file . '.bundle'] = $config;
			}
			else
			{
				$this->files[$app][$template_set]['dist'][$file . '.bundle'] = $config;
			}
			return true;
		}
		else if (is_readable(PHPGW_SERVER_ROOT . "/$app/js/$package/$file.js"))
		{
			if ($end_of_page)
			{
				$this->end_files[$app][$package][$file] = $config;
			}
			else
			{
				$this->files[$app][$package][$file] = $config;
			}
			return True;
		}
		elseif ($app != 'phpgwapi')
		{
			if (is_readable(PHPGW_SERVER_ROOT . "/phpgwapi/js/$package/$file.js"))
			{
				if ($end_of_page)
				{
					$this->end_files['phpgwapi'][$package][$file] = $config;
				}
				else
				{
					$this->files['phpgwapi'][$package][$file] = $config;
				}
				return True;
			}
			return False;
		}
	}

	public function add_code($namespace, $code, $end_of_page = false)
	{
		$key = $end_of_page ? 'java_script_end' : 'java_script';

		$script = "\n"
			. '<script>' . "\n"
			. '//<[CDATA[' . "\n"
			. $code . "\n"
			. '//]]' . "\n"
			. "</script>\n";
		$flags = Settings::getInstance()->get('flags');
		if (isset($flags[$key]))
		{
			$flags[$key] .= $script;
		}
		else
		{
			$flags[$key] = $script;
		}
		Settings::getInstance()->set('flags', $flags);
	}

	/**
	 * Adds js file to external files.
	 *
	 * @param string $file Full path to js file relative to root of phpgw install
	 */
	function add_external_file($file, $end_of_page = false, $config = array())
	{
		$_file = ltrim($file, '/');
		if (is_file(PHPGW_SERVER_ROOT . "/$_file"))
		{
			if ($end_of_page)
			{
				$this->external_end_files[$_file] = $config;
			}
			else
			{
				$this->external_files[$_file] = $config;
			}
		}
	}
}
