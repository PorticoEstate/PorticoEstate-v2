<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Translation;

/**
 * Global translation function — the primary entry point for all translation lookups.
 *
 * Exposed to three rendering systems:
 * - **XSL templates** via registerPHPFunctions() in class.xslttemplates.inc.php
 *   (XSL passes node references as DOMNode objects, hence the unwrapping below)
 * - **Twig templates** via wrapper functions in Twig.php and TwigHelper.php
 * - **PHP code** directly throughout the codebase
 *
 * Uses Translation (db-backed) when connected, falls back to SetupTranslation
 * (flat-file) during setup/install when the database may not be available.
 *
 * Supports %1, %2, ... %10 placeholders in translation strings.
 * The first substitution arg can alternatively be an array of all values.
 *
 * Supports dot-notation namespacing to force a specific module:
 *   lang('booking.my_key')  — looks up 'my_key' in the 'booking' module
 *   lang('common.yes')      — looks up 'yes' in 'common' only
 *   lang('yes')             — looks up 'yes' in current module, falls back to 'common'
 *
 * @param string       $key  Translation key, optionally prefixed with "module." namespace
 * @param string|array $arg1 First substitution value, or array of all values
 * @param string       $arg2 Second substitution value
 * @param string       $arg3 Third substitution value
 * @param string       $arg4 Fourth substitution value
 * @param string       $arg5 Fifth substitution value
 * @param string       $arg6 Sixth substitution value
 * @param string       $arg7 Seventh substitution value
 * @param string       $arg8 Eighth substitution value
 * @param string       $arg9 Ninth substitution value
 * @param string       $arg10 Tenth substitution value
 * @return string Translated string with placeholders replaced
 */
function lang($key, $arg1 = '', $arg2 = '', $arg3 = '', $arg4 = '', $arg5 = '', $arg6 = '', $arg7 = '', $arg8 = '', $arg9 = '', $arg10 = '')
{
	static $translation = null;

	if (is_array($arg1))
	{
		$vars = $arg1;
	}
	else
	{
		$vars = array($arg1, $arg2, $arg3, $arg4, $arg5, $arg6, $arg7, $arg8, $arg9, $arg10);
	}

	// XSL's php:function() passes XPath node references as DOMNode objects.
	// Unwrap them to their string values before substitution.
	foreach ($vars as &$var)
	{
		if (is_object($var) && $var instanceof DOMNode)
		{
			$var = $var->nodeValue;
		}
	}

	if (!$translation)
	{
		if(\App\Database\Db::getInstance()->isConnected())
		{
			$translation = Translation::getInstance();
		}
		else
		{
			$translation = new App\modules\phpgwapi\services\setup\SetupTranslation();
		}
	}

	// Dot-notation namespace: "module.key" forces lookup in a specific module.
	// "common.key" restricts to common translations only.
	// Only treated as a namespace if the prefix has no spaces (module names never do),
	// so existing keys like "loading..." or "no data. try again" are unaffected.
	$force_app = '';
	$only_common = false;
	$dot_pos = strpos($key, '.');
	if ($dot_pos !== false)
	{
		$namespace = substr($key, 0, $dot_pos);
		if (strpos($namespace, ' ') === false && $namespace !== '')
		{
			$key = substr($key, $dot_pos + 1);
			if ($namespace === 'common')
			{
				$only_common = true;
			}
			else
			{
				$force_app = $namespace;
			}
		}
	}

	return $translation->translate($key, $vars, $only_common, $force_app);
}

function js_lang()
{
	$keys = func_get_args();
	$strings = array();
	foreach ($keys as $key)
	{
		$strings[$key] = is_string($key) ? lang($key) : call_user_func_array('lang', $key);
	}
	return json_encode($strings);
}

/**
 * Fix global phpgw_link from XSLT templates by adding session id and click_history
 * @return string containing parts of url
 */
function get_phpgw_session_url()
{
	$base_url	= phpgw::link('/', array(), true);
	$url_parts = parse_url($base_url);
	return $url_parts['query'];
}


/**
 * Get global phpgw_info from XSLT templates
 * @param string $key on the format 'user|preferences|common|dateformat'
 * @return array or string depending on if param is representing a node
 */

function get_phpgw_info($key)
{
	$_keys = explode('|', $key);

	$ret = Settings::getInstance()->get($_keys[0]);

	//reduce the array by removing the first element
	array_shift($_keys);

	foreach ($_keys as $_var)
	{
		$ret = $ret[$_var];
	}
	return $ret;
}

/**
 * Get global phpgw_link from XSLT templates
 * @param string $path on the format 'index.php'
 * @param string $params on the format 'param1:value1,param2:value2'
 * @param boolean $redirect  want '&';rather than '&amp;';
 * @param boolean $external is the resultant link being used as external access (i.e url in emails..)
 * @param boolean $force_backend if the resultant link is being used to reference resources in the api
 * @return string containing url
 */
function get_phpgw_link($path, $params, $redirect = true, $external = false, $force_backend = false)
{
	$path = '/' . ltrim($path, '/');
	$link_data = array();

	$_param_sets = explode(',', $params);
	foreach ($_param_sets as $_param_set)
	{
		$__param_set = explode(':', $_param_set);
		if (isset($__param_set[1]) && $__param_set[1])
		{
			$link_data[trim($__param_set[0])] = trim($__param_set[1]);
		}
	}

	return phpgw::link($path, $link_data, $redirect, $external, $force_backend); //redirect: want '&';rather than '&amp;';
}

