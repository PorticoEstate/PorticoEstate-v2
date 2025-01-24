<?php

namespace App\modules\admin\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;

class ApcuController
{
	private $serverSettings;
	public function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		Settings::getInstance()->update('flags', ['menu_selection' => 'admin::admin::apcu_monitor']);

		$acl = Acl::getInstance();

		$is_admin	 = $acl->check('run', Acl::READ, 'admin');

		if (!$is_admin)
		{
			\phpgw::no_access();
		}
	}

	public function showApcuInfo(Request $request, Response $response, array $args): Response
	{
		$phpgwapi_common = new \phpgwapi_common();
		$phpgwapi_common->phpgw_header(true);

		// Check if APCu is enabled
		if (!function_exists('apcu_cache_info'))
		{
			$response->getBody()->write("No cache info available. APCu does not appear to be running.");
			return $response;
		}

		if (empty($this->serverSettings['apcu_enable']))
		{
			$response->getBody()->write("APCu is not enabled in config.");
			return $response;
		}

		if (!empty($this->serverSettings['redis_enable']) && extension_loaded('redis'))
		{
			$response->getBody()->write("APCu is enabled, but Redis is enabled in config. Please disable Redis to use APCu.");
			return $response;
		}

		// Fetch APCu cache info
		$cache = apcu_cache_info();
		$mem = apcu_sma_info();
		$time = time();
		$host = php_uname('n');
		$host = $host ? "($host)" : '';
		$host .= isset($_SERVER['SERVER_ADDR']) ? " ({$_SERVER['SERVER_ADDR']})" : '';

		// Calculate free and used memory
		$mem_size = $mem['num_seg'] * $mem['seg_size'];
		$mem_avail = $mem['avail_mem'];
		$mem_used = $mem_size - $mem_avail;

		// Create the response body

		$responseBody = "<h1>APCu INFO</h1>";
		// Add CSS to set table width
		$responseBody .= "<style>
            table.fixed-width {
                width: 100%;
                table-layout: fixed;
            }
            table.fixed-width th, table.fixed-width td {
                word-wrap: break-word;
            }
        </style>";

		// General Cache Information
		$responseBody .= "<h2>General Cache Information</h2>";
		$responseBody .=
			"<table class=\"pure-table pure-table-bordered pure-table-striped fixed-width\">";
		$responseBody .= "<tr><td>APCu Version</td><td>" . phpversion('apcu') . "</td></tr>";
		$responseBody .= "<tr><td>PHP Version</td><td>" . phpversion() . "</td></tr>";
		$responseBody .= "<tr><td>APCu Host</td><td>{$_SERVER['SERVER_NAME']} $host</td></tr>";
		$responseBody .= "<tr><td>Server Software</td><td>{$_SERVER['SERVER_SOFTWARE']}</td></tr>";
		$responseBody .= "<tr><td>Shared Memory</td><td>{$mem['num_seg']} Segment(s) with " . $this->bsize($mem['seg_size']) . " ({$cache['memory_type']} memory)</td></tr>";
		$responseBody .= "<tr><td>Start Time</td><td>" . date('Y/m/d H:i:s', $cache['start_time']) . "</td></tr>";
		$responseBody .= "<tr><td>Uptime</td><td>" . $this->duration($cache['start_time']) . "</td></tr>";
		$responseBody .= "</table>";

		// Cache Information
		$responseBody .= "<h2>Cache Information</h2>";
		$responseBody .=
			"<table class=\"pure-table pure-table-bordered pure-table-striped fixed-width\">";
		$responseBody .= "<tr><td>Cached Variables</td><td>{$cache['num_entries']} (" . $this->bsize($cache['mem_size']) . ")</td></tr>";
		$responseBody .= "<tr><td>Hits</td><td>{$cache['num_hits']}</td></tr>";
		$responseBody .= "<tr><td>Misses</td><td>{$cache['num_misses']}</td></tr>";
		$responseBody .= "<tr><td>Request Rate (hits, misses)</td><td>" . sprintf("%.2f", ($cache['num_hits'] + $cache['num_misses']) / max($time - $cache['start_time'], 1)) . " cache requests/second</td></tr>";
		$responseBody .= "<tr><td>Hit Rate</td><td>" . sprintf("%.2f", $cache['num_hits'] / max($time - $cache['start_time'], 1)) . " cache requests/second</td></tr>";
		$responseBody .= "<tr><td>Miss Rate</td><td>" . sprintf("%.2f", $cache['num_misses'] / max($time - $cache['start_time'], 1)) . " cache requests/second</td></tr>";
		$responseBody .= "<tr><td>Insert Rate</td><td>" . sprintf("%.2f", $cache['num_inserts'] / max($time - $cache['start_time'], 1)) . " cache requests/second</td></tr>";
		$responseBody .= "<tr><td>Cache full count</td><td>{$cache['expunges']}</td></tr>";
		$responseBody .= "<tr><td>Free Memory</td><td>" . $this->bsize($mem_avail) . " (" . sprintf("%.1f%%", $mem_avail * 100 / $mem_size) . ")</td></tr>";
		$responseBody .= "<tr><td>Used Memory</td><td>" . $this->bsize($mem_used) . " (" . sprintf("%.1f%%", $mem_used * 100 / $mem_size) . ")</td></tr>";
		$responseBody .= "</table>";

		// Runtime Settings
		$responseBody .= "<h2>Runtime Settings</h2>";
		$responseBody .=
			"<table class=\"pure-table pure-table-bordered pure-table-striped fixed-width\">";
		foreach (ini_get_all('apcu') as $k => $v)
		{
			$responseBody .= "<tr><td>$k</td><td>" . str_replace(',', ',<br />', $v['local_value'] ?? '') . "</td></tr>";
		}
		$responseBody .= "</table>";

		// Write the response body to the response
		$response->getBody()->write($responseBody);

		return $response;
	}

	private function bsize($s)
	{
		foreach (array('', 'K', 'M', 'G') as $i => $k)
		{
			if ($s < 1024) break;
			$s /= 1024;
		}
		return sprintf("%5.1f %sBytes", $s, $k);
	}

	private function duration($ts)
	{
		$time = time();
		$years = (int)((($time - $ts) / (7 * 86400)) / 52.177457);
		$rem = (int)(($time - $ts) - ($years * 52.177457 * 7 * 86400));
		$weeks = (int)(($rem) / (7 * 86400));
		$days = (int)(($rem) / 86400) - $weeks * 7;
		$hours = (int)(($rem) / 3600) - $days * 24 - $weeks * 7 * 24;
		$mins = (int)(($rem) / 60) - $hours * 60 - $days * 24 * 60 - $weeks * 7 * 24 * 60;
		$str = '';
		if ($years == 1) $str .= "$years year, ";
		if ($years > 1) $str .= "$years years, ";
		if ($weeks == 1) $str .= "$weeks week, ";
		if ($weeks > 1) $str .= "$weeks weeks, ";
		if ($days == 1) $str .= "$days day,";
		if ($days > 1) $str .= "$days days,";
		if ($hours == 1) $str .= " $hours hour and";
		if ($hours > 1) $str .= " $hours hours and";
		if ($mins == 1) $str .= " 1 minute";
		else $str .= " $mins minutes";
		return $str;
	}
}
