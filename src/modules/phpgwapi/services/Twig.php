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

        // Initialize the Twig loader


        // Create loader with a base path
        $this->loader = new FilesystemLoader();

        // Initialize Twig environment
        $cacheDir = SRC_ROOT_PATH . '/cache/twig';
        if (!is_dir($cacheDir))
        {
            mkdir($cacheDir, 0755, true);
        }

        $this->twig = new Environment($this->loader, [
            'cache' => $cacheDir,
            'debug' => true,
            'auto_reload' => true
        ]);

        // Add debug extension
        $this->twig->addExtension(new DebugExtension());
        
        // Register the lang function for translations
        $this->twig->addFunction(new TwigFunction('lang', function($text) {
            // Replace underscores with spaces before calling lang()
            $text = str_replace('_', ' ', $text);
            return lang($text);
        }));
        $this->twig->addFunction(new TwigFunction('hook', [$this, 'renderHook']));

        // Add a filter to replace underscores with spaces
        $this->twig->addFilter(new TwigFilter('replace_underscores', function($text) {
            return str_replace('_', ' ', $text);
        }));

        // Register paths for all modules
        $this->registerModulePaths();
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

        static $config = [];
        if (empty($config))
        {
            $c = new \App\modules\phpgwapi\services\Config($currentApp);
            $c->read();

            if ($c->config_data)
            {
                $config = $c->config_data;
            }
        }

        // Check if the hook exists and is callable
        if (is_callable($hookName))
        {
            return call_user_func($hookName, $config);
        }
        else
        {
            // Log an error if the hook is not callable
            error_log("Hook '{$hookName}' is not callable.");
            return '';
        }
    }

    /**
     * Register template paths for all modules
     */
    private function registerModulePaths()
    {
        // Register the base template directory without a namespace
        $basePath = PHPGW_SERVER_ROOT . '/phpgwapi/templates';
        if (is_dir($basePath . '/base'))
        {
            $this->loader->addPath($basePath . '/base');
        }

        if (is_dir($basePath . '/' . $this->serverSettings['template_set']))
        {
            $this->loader->addPath($basePath . '/' . $this->serverSettings['template_set']);
        }

        // Register module template directories with their module name as namespace
        $modulesDir = PHPGW_SERVER_ROOT;


        if (is_dir($modulesDir))
       {
            $modules = [$this->flags['currentapp']];

            foreach ($modules as $module)
            {
 
                $modulePath = $modulesDir . '/' . $module;

                // Only process directories
                if (!is_dir($modulePath))
                {
                    continue;
                }

                // Register module's template directory with module name as namespace

                // First check for Twig templates
                $moduleTemplateDir = $modulePath . '/templates/' . $this->serverSettings['template_set'];
                if (is_dir($moduleTemplateDir))
                {
                    // Always add both the original module name and lowercase version for compatibility
        //            $this->loader->addPath($moduleTemplateDir, $module);
                    // Also add as a general path (without namespace)
       //             $this->loader->addPath($moduleTemplateDir);
                }

                // Also check for templates in the 'base' directory
                $moduleBaseTemplateDir = $modulePath . '/templates/base';
                if (is_dir($moduleBaseTemplateDir))
                {
                    // Add base directory as a fallback path for this namespace
                    // Always add both the original module name and lowercase version for compatibility
                    $this->loader->addPath($moduleBaseTemplateDir, $module);
                    //          $this->loader->addPath($moduleBaseTemplateDir, strtolower($module));
                    // Also add as a general path (without namespace)
                    $this->loader->addPath($moduleBaseTemplateDir);
                }
            }
        }
/*
        // Debug output - list all registered namespaces and paths
        $namespaces = $this->loader->getNamespaces();
       _debug_array("Registered Twig namespaces: " . implode(", ", $namespaces));

        foreach ($namespaces as $namespace)
        {
            $paths = $this->loader->getPaths($namespace);
            _debug_array("Paths for namespace '{$namespace}': " . implode(", ", $paths));
        }
*/
    }

    /**
     * Render a template with given variables
     * 
     * @param string $template Template name (with or without namespace)
     * @param array $vars Variables to pass to the template
     * @return string Rendered template
     */
    public function render(string $template, array $vars = []): string
    {
        // Add some globals that are commonly used
        $globals = [
            'current_time' => time(),
            'user' => isset($this->userSettings['fullname']) ? $this->userSettings['fullname'] : [],
            'app' => isset($this->flags['currentapp']) ? $this->flags['currentapp'] : '',
        ];

        // Merge globals with the provided variables
        $vars = array_merge($globals, $vars);

        // Render and return the template
        return $this->twig->render($template, $vars);
    }

    /**
     * Render a specific block from a template
     * 
     * @param string $template Template name (with or without namespace)
     * @param string $blockName Name of the block to render
     * @param array $vars Variables to pass to the template
     * @param string $namespace Optional namespace for the template
     * @return string Rendered block
     */
    public function renderBlock(string $template, string $blockName, array $vars = [], string|null $namespace = null): string
    {
        // Add some globals that are commonly used
        $globals = [
            'current_time' => time(),
            'user' => isset($this->userSettings['fullname']) ? $this->userSettings['fullname'] : [],
            'app' => isset($this->flags['currentapp']) ? $this->flags['currentapp'] : '',
        ];

        // Merge globals with the provided variables
        $vars = array_merge($globals, $vars);

        try
        {
            // First try loading with the namespace prefix if provided
            if ($namespace !== null && !str_starts_with($template, '@'))
            {
                try
                {
                    $templatePath = "@{$namespace}/{$template}";
                    $templateObj = $this->twig->load($templatePath);
                    return $templateObj->renderBlock($blockName, $vars);
                }
                catch (\Twig\Error\LoaderError $e)
                {
                    // If namespace doesn't work, try without namespace
                    error_log("Failed to load template with namespace: " . $e->getMessage());
                }
            }

            // Try loading without namespace
            $templateObj = $this->twig->load($template);
            return $templateObj->renderBlock($blockName, $vars);
        }
        catch (\Exception $e)
        {
            // Log the error and return an error message
            error_log("Twig renderBlock error: " . $e->getMessage());
            
            // Fallback to direct HTML generation if Twig rendering fails
            if ($blockName === 'row_2') {
                return '<tr><td colspan="2" class="center">' . $vars['value'] . '</td></tr>';
            } else if ($blockName === 'row') {
                return '<tr class="' . $vars['tr_class'] . '"><td class="center">' . $vars['label'] . 
                       '</td><td class="center">' . $vars['value'] . '</td></tr>';
            }
            
            return "<!-- Error rendering block '{$blockName}': " . htmlspecialchars($e->getMessage()) . " -->";
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
