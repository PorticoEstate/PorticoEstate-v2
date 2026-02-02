<?php

namespace App\modules\phpgwapi\services;

use App\modules\phpgwapi\services\Settings;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

/**
 * Twig template service for the application
 */
class Twig
{
    private static $instance;
    private $twig;
    private $loader;
    private $flags;
    private $userSettings;
    private $serverSettings;
    private $designSystem;

    /**
     * Get singleton instance
     * 
     * @return Twig
     */
    public static function getInstance()
    {
        if (self::$instance === null)
        {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct()
    {
        $this->flags = Settings::getInstance()->get('flags');
        $this->userSettings = Settings::getInstance()->get('user');
        $this->serverSettings = Settings::getInstance()->get('server');
        $this->designSystem = DesignSystem::getInstance();

        // Initialize the Twig loader
        $this->loader = new FilesystemLoader();

        // Initialize Twig environment
        $debugMode = true;//!empty($this->serverSettings['debug_mode']);
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

        $this->registerFunctions();
        $this->registerFilters();
        $this->registerPaths();
        $this->registerGlobals();
    }

    /**
     * Register all Twig functions
     */
    private function registerFunctions()
    {
        // lang() function for translations
        $this->twig->addFunction(new TwigFunction('lang', function ($text, ...$args) {
            $text = str_replace('_', ' ', $text);
            return lang($text, ...$args);
        }));

        // phpGWLink function
        $this->twig->addFunction(new TwigFunction('phpgw_link', function ($path, $params = [], ...$args) {
            return \phpgw::link($path, $params, ...$args);
        }));

        // Image finder function
        $this->twig->addFunction(new TwigFunction('find_image', function ($module, $image) {
            return (new \phpgwapi_common())->find_image($module, $image);
        }));

        // Hook rendering function
        $this->twig->addFunction(new TwigFunction('hook', [$this, 'renderHook'], ['is_safe' => ['html']]));

        // Date formatting function
        $this->twig->addFunction(new TwigFunction('format_date', function ($timestamp, $format = null) {
            $common = new \phpgwapi_common();
            return $common->show_date($timestamp, $format);
        }));

        // Design system component function
        $this->twig->addFunction(new TwigFunction('ds_component', function ($component, $props = []) {
            return $this->designSystem->component($component, $props);
        }, ['is_safe' => ['html']]));

        // Design system check function
        $this->twig->addFunction(new TwigFunction('is_designsystemet', function () {
            return $this->designSystem->isEnabled();
        }));
    }

    /**
     * Register all Twig filters
     */
    private function registerFilters()
    {
        $this->twig->addFilter(new TwigFilter('replace_underscores', function ($text) {
            return str_replace('_', ' ', $text);
        }));

        // Add safe HTML filter
        $this->twig->addFilter(new TwigFilter('safe_html', function ($text) {
            return $text;
        }, ['is_safe' => ['html']]));
    }

    /**
     * Render a hook
     * 
     * @param string $hookName Name of the hook
     * @param array $args Arguments to pass to the hook
     * @return string Rendered output of the hook
     */
    public function renderHook(string $hookName, array $args = []): string
    {
        $currentApp = $this->flags['currentapp'];
        
        try {
            $config = new \App\modules\phpgwapi\services\Config($currentApp);
            $config->read();
            
            if (is_callable($hookName)) {
                return call_user_func($hookName, $config->config_data ?? []);
            }
            return '';
        } catch (\Exception $e) {
            error_log("Hook rendering error: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Register template paths for all modules
     */
    private function registerPaths()
    {
        // Register base phpgwapi paths
        $this->loader->addPath(PHPGW_SERVER_ROOT . '/phpgwapi/templates/base');
        $this->loader->addPath(PHPGW_SERVER_ROOT . '/phpgwapi/templates/' . $this->serverSettings['template_set']);

        // Register Designsystemet component templates if using digdir template
        if ($this->designSystem->isEnabled()) {
            $componentPath = PHPGW_SERVER_ROOT . '/phpgwapi/templates/digdir/components';
            if (is_dir($componentPath)) {
                $this->loader->addPath($componentPath, 'components');
            }
        }

        // Register current app paths
        $appDir = PHPGW_SERVER_ROOT . '/' . $this->flags['currentapp'];
        $baseAppTpl = $appDir . '/templates/base';
        $appTpl = $appDir . '/templates/' . $this->serverSettings['template_set'];

        if (is_dir($baseAppTpl)) {
            $this->loader->addPath($baseAppTpl, $this->flags['currentapp']);
        }
        if (is_dir($appTpl)) {
            $this->loader->addPath($appTpl, $this->flags['currentapp']);
        }
    }

    /**
     * Register global Twig variables
     */
    private function registerGlobals()
    {
        $this->twig->addGlobal('is_designsystemet', $this->designSystem->isEnabled());
        $this->twig->addGlobal('template_set', $this->serverSettings['template_set']);
    }

    /**
     * Render a template with given variables
     * 
     * @param string $template Template name
     * @param array $vars Variables to pass to the template
     * @return string Rendered template
     */
    public function render(string $template, array $vars = []): string
    {
        try {
            $globals = [
                'current_time' => time(),
                'user' => $this->userSettings['fullname'] ?? '',
                'app' => $this->flags['currentapp'] ?? '',
                'webserver_url' => $this->serverSettings['webserver_url'] ?? '/',
            ];

            $vars = array_merge($globals, $vars);
            return $this->twig->render($template, $vars);
        } catch (\Twig\Error\Error $e) {
            error_log("Twig render error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Render a specific block from a template
     * 
     * @param string $template Template name
     * @param string $blockName Name of the block to render
     * @param array $vars Variables to pass to the template
     * @param string|null $namespace Optional namespace for the template
     * @return string Rendered block
     */
    public function renderBlock(string $template, string $blockName, array $vars = [], string|null $namespace = null): string
    {
        try {
            $templateObj = $this->twig->load($template);
            return $templateObj->renderBlock($blockName, $vars);
        } catch (\Twig\Error\Error $e) {
            error_log("Twig renderBlock error for '{$blockName}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the Twig environment instance
     * 
     * @return Environment
     */
    public function getEnvironment(): Environment
    {
        return $this->twig;
    }
}
