<?php

/**
 * CSS support class
 * @copyright Copyright (C) 2005 - 2007 Free Software Foundation, Inc http://www.fsf.org/
 * @license http://www.fsf.org/licenses/gpl.html GNU General Public License
 * @package phpgwapi
 * @subpackage gui
 * @version $Id:
 */

use App\modules\phpgwapi\services\Settings;

/**
 * phpGroupWare css support class
 *
 * Only instanstiate this class using:
 * <code>
 *  phpgwapi_css::getInstance()
 * </code>
 *
 * This way a theme can see if this is a defined object and include the data,
 * while the is_object() wrapper prevents whiping out existing data held in 
 * this instance variables, primarily the $files variable.
 *
 *
 * @package phpgwapi
 * @subpackage gui
 * @uses template
 */
class phpgwapi_css
{
	/**
	 * @var array list of validated files to be included in the head section of a page
	 */
	var $files;

	/**
	 *
	 * @var array list of "external files to be included in the head section of a page
	 * Some times while using libs and such its not fesable to move css files to /tempaltes/[template]/css 
	 * because the css files are using relative paths
	 */
	var $external_files;
	var $url_files = array();

	/**
	 * @var phpgwapi_css reference to singleton instance
	 */
	private static $instance;

	/**
	 * @var string
	 */
	private $webserver_url;


	/**
	 * @var array
	 */
	private $serverSettings;

	/**
	 * @var array
	 */
	private $userSettings;

	/**
	 * Constructor
	 *
	 * Initialize the instance variables
	 */
	private function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$webserver_url		 = isset($this->serverSettings['webserver_url']) ? $this->serverSettings['webserver_url'] . PHPGW_MODULES_PATH : PHPGW_MODULES_PATH;

		$this->webserver_url = $webserver_url;
	}
	/**
	 * Gets the instance via lazy initialization (created on first usage)
	 */
	public static function getInstance(): phpgwapi_css
	{
		if (null === static::$instance)
		{
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Used for generating the list of external css files to be included in the head of a page
	 *
	 * NOTE: This method should only be called by the template class.
	 * The validation is done when the file is added so we don't have to worry now
	 *
	 * @returns string the html needed for importing the css into a page
	 */
	function get_css_links($cache_refresh_token = '')
	{
		/**
		 * 'combine' Won't work for referencing images.
		 * The image URLs are relative to CSS file location.
		 * So if you have slashes in URL, you will have to use absolute URLs or reference images from the root of the site.
		 */

		$combine = false;

		if (ini_get('suhosin.get.max_value_length') && ini_get('suhosin.get.max_value_length') < 2000)
		{
			$combine = false;
		}

		$links = '';

		$links .= "<!--CSS Imports from phpGW css class -->\n";

		// Render URL-based files first (e.g. npm packages served via /assets/npm/)
		$baseUrl = isset($this->serverSettings['webserver_url']) ? $this->serverSettings['webserver_url'] : '';
		$token = isset($this->serverSettings['cache_refresh_token']) ? '?n=' . $this->serverSettings['cache_refresh_token'] : '';
		if (!empty($this->url_files))
		{
			foreach ($this->url_files as $url)
			{
				$links .= "<link href=\"{$baseUrl}{$url}{$token}\" type=\"text/css\" rel=\"stylesheet\">\n";
			}
		}

		if (!empty($this->files) && is_array($this->files))
		{
			foreach ($this->files as $app => $tplset)
			{
				if (!empty($tplset) && is_array($tplset))
				{
					foreach ($tplset as $tpl => $files)
					{
						if (!empty($files) && is_array($files))
						{
							foreach ($files as $file => $ignored)
							{

								if ($combine)
								{
									// Add file path to array and replace path separator with "--" for URL-friendlyness
									//			$cssfiles[] = str_replace('/', '--', "{$app}/templates/{$tpl}/css/{$file}.css");
								}
								else
								{
									//echo "file: {$this->webserver_url}/{$app}/templates/{$tpl}/css/{$file}.css <br>";
									$links .= <<<HTML
					<link href="{$this->webserver_url}/{$app}/templates/{$tpl}/css/{$file}.css{$cache_refresh_token}" type="text/css" rel="stylesheet">

HTML;
								}
							}
						}
					}
				}
			}
		}


		if (!empty($this->external_files) && is_array($this->external_files))
		{
			foreach ($this->external_files as $file => $dummy)
			{
				if ($combine)
				{
					// Add file path to array and replace path separator with "--" for URL-friendlyness
					$cssfiles[] = str_replace('/', '--', $file);
				}
				else
				{
					$links .= <<<HTML
						<link href="{$this->webserver_url}/{$file}{$cache_refresh_token}" type="text/css"  rel="stylesheet">

HTML;
				}
			}
		}



		if ($combine)
		{
			$cachedir = urlencode("{$this->serverSettings['temp_dir']}/combine_cache");
			$cssfiles = implode(',', $cssfiles);
			$links .= "<link type=\"text/css\" href=\"{$this->webserver_url}/phpgwapi/inc/combine.php?cachedir={$cachedir}&type=css&files={$cssfiles}\">\n";
			unset($cssfiles);
		}


		return $links;
	}

	/**
	 * Checks to make sure a valid package and file name is provided
	 *
	 * @param string $file file to be included - no ".css" on the end
	 * @param string $app application directory to search - default = phpgwapi
	 * @returns bool was the file found?
	 */
	function validate_file($file, $app = 'phpgwapi')
	{
		if (is_readable(PHPGW_SERVER_ROOT . "/$app/templates/" . $this->userSettings['preferences']['common']['template_set'] . "/css/$file.css"))
		{
			$this->files[$app][$this->userSettings['preferences']['common']['template_set']][$file] = True;
			return True;
		}
		else if (is_readable(PHPGW_SERVER_ROOT . "/$app/templates/base/css/$file.css"))
		{
			$this->files[$app]['base'][$file] = True;
			return True;
		}
		return False;
	}

	/**
	 * Adds css file to external files.
	 *
	 * @param string $file Full path to css file relative to root of phpgw install 
	 */
	function add_external_file($file)
	{
		if (is_file(PHPGW_SERVER_ROOT . "/$file"))
		{
			$this->external_files[$file] = true;
		}
	}

	/**
	 * Add a CSS file by absolute URL path (e.g. /assets/npm/jquery-ui/dist/themes/base/jquery-ui.min.css).
	 * These are rendered as-is without PHPGW_SERVER_ROOT prefix.
	 */
	function add_url_file($url)
	{
		if (!in_array($url, $this->url_files))
		{
			$this->url_files[] = $url;
		}
	}
}
