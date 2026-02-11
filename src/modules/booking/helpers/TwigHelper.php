<?php

namespace App\modules\booking\helpers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\DesignSystem;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

/**
 * Standalone Twig rendering helper for Slim4 controllers.
 *
 * Creates its own Twig\Environment (not the legacy singleton) so controllers
 * can render templates directly, bypassing the legacy portico frame.
 *
 * Each registered function carries a "TODO: [MIGRATION]" marker indicating
 * what should replace the legacy delegate once the migration is complete.
 */
class TwigHelper
{
	private Environment $twig;
	private FilesystemLoader $loader;
	private array $flags;
	private array $userSettings;
	private array $serverSettings;
	private DesignSystem $designSystem;
	private string $appName;

	public function __construct(string $appName = 'booking')
	{
		$this->appName = $appName;

		$settings = Settings::getInstance();
		$this->flags = $settings->get('flags');
		$this->userSettings = $settings->get('user');
		$this->serverSettings = $settings->get('server');
		$this->designSystem = DesignSystem::getInstance();

		$this->loader = new FilesystemLoader();

		$debugMode = true;
		$cacheDir = SRC_ROOT_PATH . '/cache/twig';
		if (!$debugMode && !is_dir($cacheDir)) {
			mkdir($cacheDir, 0755, true);
		}

		$this->twig = new Environment($this->loader, [
			'cache' => $debugMode ? false : $cacheDir,
			'debug' => $debugMode,
			'auto_reload' => $debugMode,
			'strict_variables' => $debugMode,
		]);

		if (!empty($this->serverSettings['debug_mode'])) {
			$this->twig->addExtension(new DebugExtension());
		}

		$this->registerPaths();
		$this->registerFunctions();
		$this->registerFilters();
		$this->registerGlobals();
	}

	private function registerPaths(): void
	{
		$templateSet = $this->serverSettings['template_set'] ?? 'digdir';

		// phpgwapi base + template set
		$this->addPathIfExists(PHPGW_SERVER_ROOT . '/phpgwapi/templates/base');
		$this->addPathIfExists(PHPGW_SERVER_ROOT . '/phpgwapi/templates/' . $templateSet);

		// Designsystemet component templates
		if ($this->designSystem->isEnabled()) {
			$componentPath = PHPGW_SERVER_ROOT . '/phpgwapi/templates/digdir/components';
			$this->addPathIfExists($componentPath, 'components');
		}

		// App-specific paths (both namespaced and main namespace)
		$appDir = PHPGW_SERVER_ROOT . '/' . $this->appName;
		$baseAppTpl = $appDir . '/templates/base';
		$appTpl = $appDir . '/templates/' . $templateSet;

		$this->addPathIfExists($baseAppTpl, $this->appName);
		$this->addPathIfExists($baseAppTpl);
		$this->addPathIfExists($appTpl, $this->appName);
		$this->addPathIfExists($appTpl);

		// App component views (co-located twig/css/js)
		$this->addPathIfExists($appDir . '/components', 'views');
	}

	private function addPathIfExists(string $path, ?string $namespace = null): void
	{
		if (!is_dir($path)) {
			return;
		}
		if ($namespace !== null) {
			$this->loader->addPath($path, $namespace);
		} else {
			$this->loader->addPath($path);
		}
	}

	private function registerFunctions(): void
	{
		// TODO: [MIGRATION] Replace with i18n service
		$this->twig->addFunction(new TwigFunction('lang', function (string $text, ...$args) {
			$text = str_replace('_', ' ', $text);
			return lang($text, ...$args);
		}));

		// TODO: [MIGRATION] Replace with URL generator
		$this->twig->addFunction(new TwigFunction('phpgw_link', function (string $path, array $params = [], ...$args) {
			return \phpgw::link($path, $params, ...$args);
		}));

		// TODO: [MIGRATION] Replace with asset service
		$this->twig->addFunction(new TwigFunction('find_image', function (string $module, string $image) {
			return (new \phpgwapi_common())->find_image($module, $image);
		}));

		// TODO: [MIGRATION] Replace with event dispatcher
		$this->twig->addFunction(new TwigFunction('hook', [$this, 'renderHook'], ['is_safe' => ['html']]));

		// TODO: [MIGRATION] Replace with DateTimeFormatter
		$this->twig->addFunction(new TwigFunction('format_date', function ($timestamp, ?string $format = null) {
			return (new \phpgwapi_common())->show_date($timestamp, $format);
		}));

		// TODO: [MIGRATION] Replace with component renderer
		$this->twig->addFunction(new TwigFunction('ds_component', function (string $component, array $props = []) {
			return $this->designSystem->component($component, $props);
		}, ['is_safe' => ['html']]));

		// TODO: [MIGRATION] Remove when layout-aware
		$this->twig->addFunction(new TwigFunction('is_designsystemet', function () {
			return $this->designSystem->isEnabled();
		}));

		// TODO: [MIGRATION] Replace with settings accessor
		$this->twig->addFunction(new TwigFunction('get_phpgw_info', function (string $path) {
			return get_phpgw_info($path);
		}));

		// TODO: [MIGRATION] Replace with JSON i18n helper
		$this->twig->addFunction(new TwigFunction('js_lang', function (...$args) {
			return js_lang(...$args);
		}));

		// TODO: [MIGRATION] Replace with DateTimeFormatter
		// Inlined from booking/inc/class.uicommon.inc.php:117-146
		// to avoid pulling in the entire legacy UI class hierarchy.
		$this->twig->addFunction(new TwigFunction('pretty_timestamp', function ($date) {
			return $this->prettyTimestamp($date);
		}));
	}

	private function registerFilters(): void
	{
		$this->twig->addFilter(new TwigFilter('replace_underscores', function (string $text) {
			return str_replace('_', ' ', $text);
		}));

		$this->twig->addFilter(new TwigFilter('safe_html', function ($text) {
			return $text;
		}, ['is_safe' => ['html']]));
	}

	private function registerGlobals(): void
	{
		$this->twig->addGlobal('is_designsystemet', $this->designSystem->isEnabled());
		$this->twig->addGlobal('template_set', $this->serverSettings['template_set'] ?? 'digdir');
	}

	/**
	 * Get stylesheets needed for standalone pages (outside the legacy portico frame).
	 *
	 * @return string[] Absolute URL paths to CSS files
	 */
	public function getStandaloneStylesheets(): array
	{
		$webserverUrl = $this->serverSettings['webserver_url'] ?? '';
		$modulesPath = defined('PHPGW_MODULES_PATH') ? PHPGW_MODULES_PATH : '';
		$base = $webserverUrl . $modulesPath;

		return [
			$base . '/phpgwapi/templates/digdir/css/digdir-native.css',
		];
	}

	/**
	 * Render a hook (mirrors legacy Twig.php behaviour).
	 */
	public function renderHook(string $hookName, array $args = []): string
	{
		$currentApp = $this->flags['currentapp'] ?? $this->appName;

		try {
			$config = new \App\modules\phpgwapi\services\Config($currentApp);
			$config->read();

			if (is_callable($hookName)) {
				return call_user_func($hookName, $config->config_data ?? []);
			}
			return '';
		} catch (\Exception $e) {
			error_log("TwigHelper hook error: " . $e->getMessage());
			return '';
		}
	}

	/**
	 * Inlined pretty_timestamp from class.uicommon.inc.php:117-146.
	 *
	 * Reformats an ISO timestamp into the user's preferred date format.
	 */
	private function prettyTimestamp($date): string
	{
		if (empty($date)) {
			return '';
		}

		if (is_array($date) && is_object($date[0]) && $date[0] instanceof \DOMNode) {
			$date = $date[0]->nodeValue;
		}

		if (!preg_match('/([0-9]{4})-([0-9]{1,2})-([0-9]{1,2})( ([0-9]{2}):([0-9]{2}))?/', $date, $match)) {
			return '';
		}

		$dateformat = $this->userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d';

		if (!empty($match[4])) {
			$dateformat .= ' H:i';
			$timestamp = mktime((int)$match[5], (int)$match[6], 0, (int)$match[2], (int)$match[3], (int)$match[1]);
		} else {
			$timestamp = mktime(0, 0, 0, (int)$match[2], (int)$match[3], (int)$match[1]);
		}

		return date($dateformat, $timestamp);
	}

	/**
	 * Render a Twig template and return the HTML string.
	 */
	public function render(string $template, array $data = []): string
	{
		$modulesPath = defined('PHPGW_MODULES_PATH') ? PHPGW_MODULES_PATH : '';
		$webserverUrl = $this->serverSettings['webserver_url'] ?? '';

		$globals = [
			'current_time' => time(),
			'user' => $this->userSettings['fullname'] ?? '',
			'app' => $this->appName,
			'webserver_url' => $webserverUrl,
			'modules_url' => $webserverUrl . $modulesPath,
		];

		$vars = array_merge($globals, $data);

		return $this->twig->render($template, $vars);
	}

	/**
	 * Get the underlying Twig environment (for advanced usage / testing).
	 */
	public function getEnvironment(): Environment
	{
		return $this->twig;
	}
}
