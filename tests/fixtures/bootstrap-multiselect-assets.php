<?php

namespace App\modules\phpgwapi\services
{
	class Settings
	{
		private static $instance;

		public static function getInstance()
		{
			return self::$instance ?? (self::$instance = new self());
		}

		public function get($name)
		{
			if ($name === 'user')
			{
				return array('preferences' => array('common' => array('template_set' => $_SERVER['argv'][1])));
			}
			if ($name === 'flags')
			{
				return array('currentapp' => $_SERVER['argv'][1]);
			}
			return array();
		}
	}
}

namespace
{
	class phpgw
	{
		public static function import_class($class) {}
	}

	class phpgwapi_js
	{
		private static $instance;

		public static function getInstance()
		{
			return self::$instance ?? (self::$instance = new self());
		}

		public function add_url_file($file, $endOfPage = false) {}
		public function validate_file($package, $file)
		{
			return true;
		}
	}

	class phpgwapi_css
	{
		private static $instance;
		public $files = array();

		public static function getInstance()
		{
			return self::$instance ?? (self::$instance = new self());
		}

		public function add_external_file($file)
		{
			$this->files[] = $file;
		}

		public function add_url_file($file) {}
	}

	require dirname(__DIR__, 2) . '/src/modules/phpgwapi/inc/class.jquery.inc.php';
	\phpgwapi_jquery::load_widget('bootstrap-multiselect');
	echo json_encode(\phpgwapi_css::getInstance()->files, JSON_THROW_ON_ERROR);
}
