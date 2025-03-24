<?php

$rootDir = dirname(__DIR__, 2);

if (is_file($rootDir . '/config/header.inc.php'))
{
	$settings = require $rootDir . '/config/header.inc.php';
	$phpgw_domain = $settings['phpgw_domain'];
}
else
{
	return [];
}

$_phpgw_domains = array_keys($phpgw_domain);
$default_domain = $_phpgw_domains[0];

if (isset($_POST['FormDomain']))
{
	//avoid confusion with cookies
	unset($_COOKIE['domain']);
	setcookie('domain', '', [
		'expires' => time() - 3600, // 1 hour
		'path' => '/',
		'samesite' => 'Lax'
	]);
}

if (isset($_POST['login']))	// on login
{
	$login = $_POST['login'];
	$_logindomain = \Sanitizer::get_var('logindomain', 'string', 'POST', $default_domain);
	if (strstr($login, '#') === False)
	{
		$login .= '#' . $_logindomain;
	}
	list(, $user_domain) = explode('#', $login);
}
else if (\Sanitizer::get_var('domain', 'string', 'REQUEST', false))
{
	// on "normal" pageview
	if (!$user_domain = \Sanitizer::get_var('domain', 'string', 'GET', false))
	{
		if (!$user_domain = \Sanitizer::get_var('domain', 'string', 'POST', false))
		{
			$user_domain = \Sanitizer::get_var('domain', 'string', 'COOKIE', false);
		}
	}
}
else if (isset($_POST['FormDomain']))
{
	$user_domain = \Sanitizer::get_var('FormDomain', 'string', 'POST', $default_domain);
}
else if (isset($_COOKIE['ConfigDomain']))
{
	$user_domain =	\Sanitizer::get_var('ConfigDomain', 'string', 'COOKIE', false);
}
/**
 * Cron-Job
 */
else if (isset($_GET['domain']))
{
	$user_domain =	\Sanitizer::get_var('domain', 'string', 'GET', false);
}
else
{
	$user_domain = \Sanitizer::get_var('last_domain', 'string', 'COOKIE', false);
}

$db_server = [];
if (isset($phpgw_domain[$user_domain]))
{
	$db_server['db_host']			= $phpgw_domain[$user_domain]['db_host'];
	$db_server['db_port']			= $phpgw_domain[$user_domain]['db_port'];
	$db_server['db_name']			= $phpgw_domain[$user_domain]['db_name'];
	$db_server['db_user']			= $phpgw_domain[$user_domain]['db_user'];
	$db_server['db_pass']			= $phpgw_domain[$user_domain]['db_pass'];
	$db_server['db_type']			= $phpgw_domain[$user_domain]['db_type'];
	$db_server['domain']      = $user_domain;
}
else
{
	$db_server['db_host']			= $phpgw_domain[$default_domain]['db_host'];
	$db_server['db_port']			= $phpgw_domain[$default_domain]['db_port'];
	$db_server['db_name']			= $phpgw_domain[$default_domain]['db_name'];
	$db_server['db_user']			= $phpgw_domain[$default_domain]['db_user'];
	$db_server['db_pass']			= $phpgw_domain[$default_domain]['db_pass'];
	$db_server['db_type']			= $phpgw_domain[$default_domain]['db_type'];
	$db_server['domain']      = $default_domain;
}
return $db_server;
