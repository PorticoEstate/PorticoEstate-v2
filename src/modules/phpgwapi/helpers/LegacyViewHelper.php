<?php

namespace App\modules\phpgwapi\helpers;

use App\modules\phpgwapi\services\Settings;

/**
 * Renders component HTML inside the legacy portico frame (header, navbar, sidebar, footer).
 *
 * Uses output buffering to capture the legacy phpgw_header / phpgw_footer / parse_footer_end
 * pipeline, then returns the full HTML page as a string for the Slim4 PSR-7 response.
 *
 * The legacy pipeline normally relies on direct echo + shutdown functions:
 *   1. phpgw_header(true)  -> echoes <html><head> + navbar/sidebar (opens content wrappers)
 *   2. App content echoed  -> goes into the content area
 *   3. phpgw_footer()      -> includes footer.inc.php (app-specific footer, debug timer)
 *   4. parse_footer_end()  -> shutdown function that renders footer.twig (closes wrappers)
 *
 * We call parse_footer_end() manually inside the output buffer so its shutdown-function
 * invocation becomes a no-op (static guard: $footer_included).
 */
class LegacyViewHelper
{
	public function __construct()
	{
		// Resolve template_set from user preferences early, before any Twig
		// or DesignSystem singletons are created. In the StartPoint flow this
		// happens automatically; Slim4 routes bypass it.
		self::resolveTemplateSet();
	}

	/**
	 * Ensure server['template_set'] is set from user preferences.
	 *
	 * Must be called before DesignSystem::getInstance() to ensure isEnabled()
	 * returns the correct value (the DesignSystem singleton is immutable once created).
	 */
	public static function resolveTemplateSet(): void
	{
		$serverSettings = Settings::getInstance()->get('server');
		if (!empty($serverSettings['template_set'])) {
			return;
		}
		$userSettings = Settings::getInstance()->get('user');
		$serverSettings['template_set'] = $userSettings['preferences']['common']['template_set'] ?? 'digdir';
		Settings::getInstance()->set('server', $serverSettings);
	}

	/**
	 * Wrap component HTML in the full legacy portico frame.
	 *
	 * @param string $componentHtml  The rendered component HTML (from TwigHelper)
	 * @param string|array $appNameOrPath  Application name string, OR an array of menu path
	 *                                     segments (e.g. ['booking', 'buildings', 'documents']).
	 *                                     When an array is given, the first element is the app name,
	 *                                     menu_selection is derived as 'a::b::c', and app_header
	 *                                     is built by translating each segment via lang().
	 * @param string $menuSelection  Sidebar menu path (ignored when $appNameOrPath is an array)
	 * @return string Complete HTML page including legacy header, navbar, sidebar, and footer
	 */
	public function render(string $componentHtml, string|array $appNameOrPath = 'booking', string $menuSelection = ''): string
	{
		$this->loadLegacyClasses();

		if (is_array($appNameOrPath)) {
			$appName = $appNameOrPath[0];
			$menuSelection = implode('::', $appNameOrPath);
			$appHeader = implode('::', array_map(fn($s) => lang($s), $appNameOrPath));
			$this->setFlags($appName, $menuSelection, $appHeader);
		} else {
			// setFlags AFTER loadLegacyClasses because LegacyObjectHandler.php sets noheader=true
			$this->setFlags($appNameOrPath, $menuSelection);
		}

		$common = new \phpgwapi_common();

		ob_start();

		try {
			// 1. Header + navbar + sidebar (opens all content wrappers)
			$common->phpgw_header(true);

			// 2. Component content
			echo $componentHtml;

			// 3. App-specific footer includes + debug timer
			$common->phpgw_footer();

			// 4. Close all wrappers (footer.twig) — called manually so the
			//    shutdown-function invocation becomes a no-op via static guard
			if (function_exists('parse_footer_end')) {
				parse_footer_end();
			}

			return ob_get_clean();
		} catch (\Throwable $e) {
			ob_end_clean();
			throw $e;
		}
	}

	private function loadLegacyClasses(): void
	{
		if (!function_exists('include_class')) {
			require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		}
		if (!class_exists('phpgwapi_common', false)) {
			require_once PHPGW_SERVER_ROOT . '/phpgwapi/inc/class.common.inc.php';
		}
	}

	private function setFlags(string $appName, string $menuSelection, string $appHeader = ''): void
	{
		$flags = Settings::getInstance()->get('flags');
		$flags['currentapp'] = $appName;
		$flags['noheader'] = false;
		$flags['nonavbar'] = false;
		$flags['nofooter'] = false;
		if ($menuSelection !== '') {
			$flags['menu_selection'] = $menuSelection;
		}
		if ($appHeader !== '') {
			$flags['app_header'] = $appHeader;
		}
		// head.inc.php uses isset() not empty(), so we must unset rather than set false
		unset($flags['noframework']);
		Settings::getInstance()->set('flags', $flags);
	}
}
