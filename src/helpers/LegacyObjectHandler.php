<?php

use App\modules\phpgwapi\services\Settings;

include_once SRC_ROOT_PATH . '/modules/phpgwapi/inc/class.object_factory.inc.php';
include_once SRC_ROOT_PATH . '/modules/phpgwapi/inc/class.ofphpgwapi.inc.php';
include_once SRC_ROOT_PATH . '/helpers/phpgw.php';



if (isset($_GET['menuaction']) || isset($_POST['menuaction']))
{
	if (isset($_GET['menuaction']))
	{
		list($app, $class, $method) = explode('.', $_GET['menuaction']);
	}
	else
	{
		list($app, $class, $method) = explode('.', $_POST['menuaction']);
	}
	if (!$app || !$class || !$method)
	{
		$invalid_data = true;
	}
}
else
{

	$app = 'home';
	$invalid_data = true;
}

$api_requested = false;
if ($app == 'phpgwapi')
{
	$app = 'home';
	$api_requested = true;
}

$flags = Settings::getInstance()->get('flags');
$flags['noheader']   = true;
if (empty($flags['currentapp']))
{
	$flags['currentapp']   = $app;
}

Settings::getInstance()->set('flags', $flags);



/* 
define('PHPGW_TEMPLATE_DIR', ExecMethod('phpgwapi.phpgw.common.get_tpl_dir', 'phpgwapi'));
define('PHPGW_IMAGES_DIR', ExecMethod('phpgwapi.phpgw.common.get_image_path', 'phpgwapi'));
define('PHPGW_IMAGES_FILEDIR', ExecMethod('phpgwapi.phpgw.common.get_image_dir', 'phpgwapi'));
define('PHPGW_APP_ROOT', ExecMethod('phpgwapi.phpgw.common.get_app_dir'));
define('PHPGW_APP_INC', ExecMethod('phpgwapi.phpgw.common.get_inc_dir'));
define('PHPGW_APP_TPL', ExecMethod('phpgwapi.phpgw.common.get_tpl_dir'));
define('PHPGW_IMAGES', ExecMethod('phpgwapi.phpgw.common.get_image_path'));
define('PHPGW_APP_IMAGES_DIR', ExecMethod('phpgwapi.phpgw.common.get_image_dir'));
 */
include_once SRC_ROOT_PATH . '/modules/phpgwapi/inc/class.xslttemplates.inc.php';


/**
 * Import a class, should be used in the top of each class, doesn't instantiate like createObject does
 *
 * @internal when calling static methods, phpgw::import_class() should be called to ensure it is available
 * @param string $clasname the class to import module.class
 */
function import_class($classname)
{
	$parts = explode('.', $classname);

	if (count($parts) != 2)
	{
		throw new \RuntimeException(lang('Invalid class: %1', $classname));
	}

	if (!include_class($parts[0], $parts[1]))
	{
		$bt = debug_backtrace();

		throw new \RuntimeException(lang('Unable to load class: %1', $classname) . " called from function: {$bt[0]['function']} file: {$bt[0]['file']} line: {$bt[0]['line']}");
	}
}

/**
 * This will include an application class once and guarantee that it is loaded only once.  Similar to CreateObject, but does not instantiate the class.
 *
 * @example include_class('projects', 'ui_base');
 * @param $module name of module
 * @param $class_name name of class
 * @param $include_path path to the module class, default is 'inc/', use this parameter i.e. if the class is located in a subdirectory like 'inc/base_classes/'
 * @return boolean true if class is included, else false (false means class could not included)
 */
function include_class($module, $class_name, $includes_path = 'inc/')
{
	if (is_file(PHPGW_SERVER_ROOT . "/{$module}/{$includes_path}class.{$class_name}.inc.php"))
	{
		return require_once(PHPGW_SERVER_ROOT . "/{$module}/{$includes_path}class.{$class_name}.inc.php");
	}
	//trigger_error(lang('Unable to locate file: %1', "{$module}/{$includes_path}class.{$class_name}.inc.php"), E_USER_ERROR);
	return false;
}

/**
 * delegate the object creation into the module.
 *
 * @author Dirk Schaller
 * @author Phillip Kamps
 * This function is used to create an instance of a class. Its delegates the creation process into the called module and its factory class. If a module has no factory class, then its use the old CreateObject method. The old CreateObject method is moved into the base object factory class.
 * $GLOBALS['phpgw']->acl = createObject('phpgwapi.acl');
 * @param $classname name of class
 * @param $p1-$p16 class parameters (all optional)
 */
function createObject(
	$class,
	$p1 = '_UNDEF_',
	$p2 = '_UNDEF_',
	$p3 = '_UNDEF_',
	$p4 = '_UNDEF_',
	$p5 = '_UNDEF_',
	$p6 = '_UNDEF_',
	$p7 = '_UNDEF_',
	$p8 = '_UNDEF_',
	$p9 = '_UNDEF_',
	$p10 = '_UNDEF_',
	$p11 = '_UNDEF_',
	$p12 = '_UNDEF_',
	$p13 = '_UNDEF_',
	$p14 = '_UNDEF_',
	$p15 = '_UNDEF_',
	$p16 = '_UNDEF_'
)
{

	list($appname, $classname) = explode('.', $class, 2);

	$of_classname = "of{$appname}";

	// include module object factory class
	if (!include_class($appname, $of_classname))
	{
		// fail to load module object factory -> use old CreateObject in base class
		$of_classname = 'phpgwapi_object_factory';
	}
	else
	{
		$of_classname = "{$appname}_{$of_classname}";
	}

	// because $of_classname::CreateObject() is not allowed, we use call_user_func
	return call_user_func(
		"{$of_classname}::createObject",
		$class,
		$p1,
		$p2,
		$p3,
		$p4,
		$p5,
		$p6,
		$p7,
		$p8,
		$p9,
		$p10,
		$p11,
		$p12,
		$p13,
		$p14,
		$p15,
		$p16
	);
}

/**
 * Execute a function, and load a class and include the class file if not done so already.
 *
 * @author seek3r
 * This function is used to create an instance of a class, and if the class file has not been included it will do so.
 * @param $method to execute
 * @param $functionparams function param should be an array
 * @param $loglevel developers choice of logging level
 * @param $classparams params to be sent to the contructor
 * ExecObject('phpgwapi.acl.read');
 */
function ExecMethod($method, $functionparams = '_UNDEF_', $loglevel = 3, $classparams = '_UNDEF_')
{
	/* Need to make sure this is working against a single dimensional object */
	$partscount = count(explode('.', $method)) - 1;
	if ($partscount == 2)
	{
		list($appname, $classname, $functionname) = explode(".", $method);
		$unique_class = "{$appname}_{$classname}";
		if (!isset($GLOBALS['phpgw_classes'][$unique_class]) || !is_object($GLOBALS['phpgw_classes'][$unique_class]))
		{
			if ($classparams != '_UNDEF_' && ($classparams || $classparams != 'True'))
			{
				$GLOBALS['phpgw_classes'][$unique_class] = createObject("{$appname}.{$classname}", $classparams);
			}
			else
			{
				$GLOBALS['phpgw_classes'][$unique_class] = createObject("{$appname}.{$classname}");
			}
		}

		if ((is_array($functionparams) || is_object($functionparams) || $functionparams != '_UNDEF_')
			&& ($functionparams || $functionparams != 'True')
		)
		{
			return $GLOBALS['phpgw_classes'][$unique_class]->$functionname($functionparams);
		}
		else
		{
			return $GLOBALS['phpgw_classes'][$unique_class]->$functionname();
		}
	}
	/* if the $method includes a parent class (multi-dimensional) then we have to work from it */
	elseif ($partscount >= 3)
	{
		$GLOBALS['methodparts'] = explode(".", $method);
		$classpartnum = $partscount - 1;
		$appname = $GLOBALS['methodparts'][0];
		$classname = $GLOBALS['methodparts'][$classpartnum];
		$functionname = $GLOBALS['methodparts'][$partscount];
		/* Now I clear these out of the array so that I can do a proper */
		/* loop and build the $parentobject */
		unset($GLOBALS['methodparts'][0]);
		unset($GLOBALS['methodparts'][$classpartnum]);
		unset($GLOBALS['methodparts'][$partscount]);
		reset($GLOBALS['methodparts']);
		$parentobject = '';
		$firstparent = True;
		foreach ($GLOBALS['methodparts'] as $key => $val)
		{
			if ($firstparent == True)
			{
				$parentobject = '$GLOBALS["' . $val . '"]';
				$firstparent = False;
			}
			else
			{
				$parentobject .= '->' . $val;
			}
		}
		unset($GLOBALS['methodparts']);

		if (
			!isset($$parentobject->$classname)
			|| !is_object($$parentobject->$classname)
		)
		{
			if ($classparams != '_UNDEF_' && ($classparams || $classparams != 'True'))
			{
				$$parentobject->$classname = createObject($appname . '.' . $classname, $classparams);
			}
			else
			{
				$$parentobject = new stdClass();
				$$parentobject->$classname = createObject($appname . '.' . $classname);
			}
		}

		if ($functionparams != '_UNDEF_' && ($functionparams || $functionparams != 'True'))
		{
			return $$parentobject->$classname->$functionname($functionparams);
		}
		else
		{
			return $returnval = $$parentobject->$classname->$functionname();
		}
	}
	else
	{
		return 'error in parts';
	}
}
