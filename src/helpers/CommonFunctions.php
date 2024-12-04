<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Translation;

/**
 * Translate a string to a user's prefer language - convience method
 *
 * @param string $key phrase to translate (note: %n are replaces with $mn)
 * @param string $m1 substitution string
 * @param string $m1 substitution string
 * @param string $m2 substitution string
 * @param string $m3 substitution string
 * @param string $m4 substitution string
 * @param string $m5 substitution string
 * @param string $m6 substitution string
 * @param string $m7 substitution string
 * @param string $m8 substitution string
 * @param string $m9 substitution string
 * @param string $m10 substitution string
 * @returns string translated phrase
 */
function lang($key, $m1 = '', $m2 = '', $m3 = '', $m4 = '', $m5 = '', $m6 = '', $m7 = '', $m8 = '', $m9 = '', $m10 = '')
{
	static $translation = null;
	if (is_array($m1))
	{
		$vars = $m1;
	}
	else
	{
		$vars = array($m1, $m2, $m3, $m4, $m5, $m6, $m7, $m8, $m9, $m10);
	}

	// Support DOMNodes from XSL templates
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
	return $translation->translate($key, $vars);
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

