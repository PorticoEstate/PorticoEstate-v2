<?php

/**
 * phpGroupWare - Manual
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2010 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package manual
 * @version $Id$
 */

use App\modules\phpgwapi\services\Hooks;
use App\modules\phpgwapi\services\Settings;
use App\helpers\Template;
use lebedevsergey\ODT2XHTML\Helpers\FilesHelper;
use lebedevsergey\ODT2XHTML\ODT2XHTML;

/**
 * Manual Renderer
 * @package manual
 */
class manual_uimanual
{

	var $grants;
	var $start;
	var $query;
	var $sort;
	var $order;
	var $sub;
	var $currentapp;
	var $hooks;
	var $phpgwapi_common;
	var $serverSettings;
	var $userSettings;
	var $apps;
	var $flags;
	var $public_functions = array(
		'index' => true,
		'help' => true,
		'attrib_help' => true
	);

	public function __construct()
	{
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->apps = Settings::getInstance()->get('apps');
		$this->flags = Settings::getInstance()->get('flags');

	}

	function index()
	{
		$this->currentapp = Sanitizer::get_var('app');
		$this->hooks = new Hooks();

		if (!$this->currentapp || $this->currentapp == 'manual')
		{
			$this->currentapp = 'help';
		}

		if ($this->currentapp == 'help')
		{
			$this->hooks->process('help', array('manual'));
		}
		else
		{
			$this->hooks->single('help', $this->currentapp);
		}

		$appname = lang('Help');
		$function_msg = lang($this->currentapp);

		Settings::getInstance()->update('flags', ['app_header' => $appname . ' - ' . $function_msg]);

		$this->phpgwapi_common->phpgw_header(true);
	}


	function help_file_exist()
	{
		$app = $this->flags['currentapp'];
		$section = isset($this->apps['manual']['section']) ? $this->apps['manual']['section'] : '';
		$referer = Sanitizer::get_var('menuaction');

		if (!$section)
		{
			$menuaction = $referer;
			if ($menuaction)
			{
				list($app_from_referer, $class, $method) = explode('.', $menuaction);
				if (strpos($class, 'ui') === 0)
				{
					$class = ltrim($class, 'ui');
				}
				$section = "{$class}.{$method}";
			}
		}

		if (!$app)
		{
			$app = isset($app_from_referer) && $app_from_referer ? $app_from_referer : 'manual';
		}

		$section = $section ? $section : 'overview';
		$lang = strtoupper(isset($this->userSettings['preferences']['common']['lang']) && $this->userSettings['preferences']['common']['lang'] ? $this->userSettings['preferences']['common']['lang'] : 'en');

		$pdffile = PHPGW_SERVER_ROOT . "/{$app}/help/{$lang}/{$section}.pdf";

		$file_exist = false;
		if (is_file($pdffile))
		{
			$file_exist = true;
		}
		$odtfile = PHPGW_SERVER_ROOT . "/{$app}/help/{$lang}/{$section}.odt";


		if (is_file($odtfile))
		{
			$file_exist = true;
		}

		return array(
			'app' => $app,
			'section' => $section,
			'referer' => $referer,
			'file_exist' => $file_exist
		);
	}

	function help()
	{
		Settings::getInstance()->update('flags', ['noframework' => true, 'no_reset_fonts' => true]);
		$app = Sanitizer::get_var('app', 'string', 'GET');
		$section = Sanitizer::get_var('section', 'string', 'GET');


		if (!$section)
		{
			$menuaction = Sanitizer::get_var('referer');
			if ($menuaction)
			{
				list($app_from_referer, $class, $method) = explode('.', $menuaction);
				if (strpos($class, 'ui') === 0)
				{
					$class = ltrim($class, 'ui');
				}
				$section = "{$class}.{$method}";
			}
		}

		if (!$app)
		{
			$app = isset($app_from_referer) && $app_from_referer ? $app_from_referer : 'manual';
		}

		$section = $section ? $section : 'overview';
		$lang = strtoupper(isset($this->userSettings['preferences']['common']['lang']) && $this->userSettings['preferences']['common']['lang'] ? $this->userSettings['preferences']['common']['lang'] : 'en');
		$navbar = Sanitizer::get_var('navbar', 'string', 'GET');

		$pdffile = PHPGW_SERVER_ROOT . "/{$app}/help/{$lang}/{$section}.pdf";

		/*
			  if(is_file($pdffile))
			  {
			  $content = file_get_contents($pdffile);
			  $browser = CreateObject('phpgwapi.browser');
			  $browser->content_header("{$section}.pdf", 'application/pdf', strlen($content));
			  echo $content;
			  $this->phpgwapi_common->phpgw_exit();
			  }
			 */


		if (is_file($pdffile))
		{
			$browser = CreateObject('phpgwapi.browser');
		
			$browser->content_header("{$section}.pdf", '', filesize($pdffile));
			ob_clean();
			flush();
			readfile($pdffile);
			
			$this->phpgwapi_common->phpgw_exit();
		}


		Settings::getInstance()->update('flags', ['app_header' => $app . '::' . lang($section)]);
		$this->phpgwapi_common->phpgw_header();
		if ($navbar)
		{
			$this->hooks->process('help', array('manual'));
			parse_navbar();
		}

		$odtfile = PHPGW_SERVER_ROOT . "/{$app}/help/{$lang}/{$section}.odt";

		// test the manual on odt2xhtml
		//$odtfile = PHPGW_SERVER_ROOT . '/phpgwapi/inc/odt2xhtml/odt2xhtml.odt';

		if (is_file($odtfile))
		{
			$frontend = '/'; # directory where file odt to converse
			$root = $this->serverSettings['temp_dir'];
			$ODTHTMLPath = $root . '/odt_html';

			FilesHelper::deleteDirRecursive($ODTHTMLPath); // delete previous HTML
			$converter = new ODT2XHTML();
			$converter->convert($odtfile, $ODTHTMLPath, true);
			//"/tmp/location.index.xml"
//list contend of directory $ODTHTMLPath
			$dir = opendir($ODTHTMLPath);
			while ($file = readdir($dir))
			{
				if ($file != '.' && $file != '..')
				{
					echo $file . '<br>';
				}
			} 
			$html = file_get_contents($ODTHTMLPath);
			echo $html;

		}
		else
		{
			$error = lang('Invalid or missing manual entry requested, please contact your system administrator');
			echo <<<HTML
					<div class="err">$error</div>

HTML;
		}

		$this->phpgwapi_common->phpgw_footer();
	}

	function attrib_help()
	{
		$t = new Template(PHPGW_APP_TPL);

		Settings::getInstance()->update('flags', ['noframework' => true, 'xslt_app' => false, 'nofooter' => true]);

		$appname = Sanitizer::get_var('appname');
		$location = Sanitizer::get_var('location');
		$id = Sanitizer::get_var('id', 'int');

		$custom_fields = CreateObject('phpgwapi.custom_fields');
		$attrib_data = $custom_fields->get($appname, $location, $id);

		$helpmsg = nl2br(str_replace(array(
			'[',
			']'
		), array(
			'<',
			'>'
		), $attrib_data['helpmsg']));

		$function_msg = lang('Help');

		$t->set_file('help', 'help.tpl');
		$t->set_var('title', lang('Help') . " - \"{$attrib_data['input_text']}\"");
		$t->set_var('help_msg', $helpmsg);
		$t->set_var('lang_close', lang('close'));

		$this->phpgwapi_common->phpgw_header();
		$t->pfp('out', 'help');
	}
}
