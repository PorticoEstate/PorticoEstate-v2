<?php

namespace App\modules\admin\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use Sanitizer;

class OpcacheController
{
	private $phpgwapi_common;

	private function stripOuterDocumentTags(string $html): string
	{
		// Keep scripts/styles/content and only remove wrapper tags.
		$html = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $html, 1) ?? $html;
		$html = preg_replace('/^\s*<html\b[^>]*>\s*/i', '', $html, 1) ?? $html;
		$html = preg_replace('/\s*<head\b[^>]*>\s*/i', '', $html, 1) ?? $html;
		$html = preg_replace('/\s*<meta\b[^>]*>\s*/i', '', $html) ?? $html;
		$html = preg_replace('/\s*<title\b[^>]*>.*?<\/title>\s*/is', '', $html, 1) ?? $html;
		$html = preg_replace('/\s*<\/head>\s*/i', '', $html, 1) ?? $html;
		$html = preg_replace('/\s*<body\b[^>]*>\s*/i', '', $html, 1) ?? $html;
		$html = preg_replace('/\s*<\/body>\s*$/i', '', $html, 1) ?? $html;
		$html = preg_replace('/\s*<\/html>\s*$/i', '', $html, 1) ?? $html;

		return $html;
	}

	public function __construct()
	{
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		Settings::getInstance()->update('flags', ['menu_selection' => 'admin::admin::opcache_monitor']);

		$acl = Acl::getInstance();

		$is_admin	 = $acl->check('run', Acl::READ, 'admin');

		if (!$is_admin)
		{
			\phpgw::no_access();
		}
	}

	public function showOpcacheGui(Request $request, Response $response, array $args): Response
	{
		$refresh = false;
		$vendordir = dirname(PHPGW_SERVER_ROOT, 2) . '/vendor';
		if (Sanitizer::get_var('reset', 'get', 'int') == 1)
		{
			opcache_reset();
			$refresh = true;
		}

		$filename = Sanitizer::get_var('invalidate', 'get', 'string');
		if ($filename)
		{
			opcache_invalidate($filename, true);
			$refresh = true;
		}

		if ($refresh)
		{
			// Refresh the current page using HTTP_REFERER
			if (isset($_SERVER['HTTP_REFERER']))
			{
				header("Location: " . $_SERVER['HTTP_REFERER']);
				exit;
			}
			else
			{
				// Fallback to a default URL if HTTP_REFERER is not set
				\phpgw::redirect('/admin/admin/opcache/');
			}
		}

		if (Sanitizer::get_var('click_history', 'get', 'bool'))
		{
			$phpgwapi_common = new \phpgwapi_common();
			$phpgwapi_common->phpgw_header(true);
		}

		ob_start();
		require_once $vendordir . '/amnuts/opcache-gui/index.php';
		$opcacheGuiHtml = ob_get_clean() ?: '';
		$opcacheGuiHtml = $this->stripOuterDocumentTags($opcacheGuiHtml);

		$response->getBody()->write($opcacheGuiHtml);
		return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
	}
}
