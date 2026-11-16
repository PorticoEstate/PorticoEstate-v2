<?php

namespace App\modules\phpgwapi\services
{
	class Settings
	{
		private static $instance;
		private $values;

		public static function getInstance()
		{
			return self::$instance ?? (self::$instance = new self());
		}

		public function __construct()
		{
			$noFramework = $_SERVER['argv'][3] === 'no-framework';
			$common = array();
			if (isset($_SERVER['argv'][4]))
			{
				$common['theme'] = $_SERVER['argv'][4];
			}
			$this->values = array(
				'server' => array('site_title' => 'Test', 'webserver_url' => '/portico', 'temp_dir' => '/tmp'),
				'flags' => array('currentapp' => $_SERVER['argv'][2], 'noframework' => $noFramework, 'nonavbar' => $noFramework),
				'user' => array('preferences' => array('common' => $common)),
			);
		}

		public function get($name)
		{
			return $this->values[$name];
		}

		public function set($name, $value)
		{
			$this->values[$name] = $value;
		}
	}
}

namespace App\helpers
{
	class Template
	{
		public $stylesheets = array();
		private $stylesheet;

		public function __construct($path) {}
		public function set_unknowns($value) {}
		public function set_file($name, $file) {}
		public function set_block($name, $block, $target) {}
		public function set_var($name, $value)
		{
			if ($name === 'stylesheet_uri')
			{
				$this->stylesheet = $value;
			}
		}
		public function parse($target, $block, $append)
		{
			if ($target === 'stylesheets')
			{
				$this->stylesheets[] = $this->stylesheet;
			}
		}
	}
}

namespace
{
	class phpgw
	{
		public static function import_class($class) {}
	}

	class phpgwapi_jquery
	{
		public static function load_widget($widget) {}
	}

	class StylesheetsCollected extends \RuntimeException {}

	function execMethod($method)
	{
		throw new StylesheetsCollected();
	}

	$root = dirname(__DIR__, 2);
	define('PHPGW_SERVER_ROOT', $root . '/src/modules');
	define('PHPGW_MODULES_PATH', '/src/modules');
	define('PHPGW_TEMPLATE_DIR', PHPGW_SERVER_ROOT . '/phpgwapi/templates/' . $_SERVER['argv'][1]);

	try
	{
		require PHPGW_TEMPLATE_DIR . '/head.inc.php';
		throw new \RuntimeException('The head did not reach stylesheet collection.');
	}
	catch (StylesheetsCollected $exception)
	{
		echo json_encode($_SERVER['argv'][1] === 'portico' ? $template->stylesheets : $cssUris, JSON_THROW_ON_ERROR);
	}
}
