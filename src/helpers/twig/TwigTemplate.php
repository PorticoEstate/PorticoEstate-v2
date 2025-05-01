<?php

namespace App\helpers\twig;

use App\modules\phpgwapi\services\Settings;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * TwigTemplate - A Twig adapter for the legacy Template class
 * This class provides the same interface as the legacy Template class
 * but uses Twig as the underlying template engine.
 */
class TwigTemplate
{
    /**
     * The Twig environment
     * @var Environment
     */
    protected $twig;

    /**
     * The template loader
     * @var FilesystemLoader
     */
    protected $loader;

    /**
     * Template variables
     * @var array
     */
    protected $varvals = [];

    /**
     * File mappings
     * @var array
     */
    protected $file = [];

    /**
     * The base directory from which template files are loaded
     * @var string
     */
    protected $root = ".";

    /**
     * Policy for handling unknown variables
     * @var string
     */
    protected $unknowns = "remove";

    /**
     * Block mappings (for compatibility with the legacy template system)
     * @var array
     */
    protected $blocks = [];

    /**
     * Debug level
     * @var int
     */
    protected $debug = 0;

    /**
     * Server settings
     * @var array
     */
    protected $serverSettings = [];

    /**
     * Last error message
     * @var string
     */
    protected $last_error = "";

    /**
     * Error handling mode
     * @var string
     */
    protected $halt_on_error = "yes";

    /**
     * Generate comments in output with filenames
     * @var bool
     */
    protected $filename_comments = false;

    /**
     * @var TwigTemplate reference to singleton instance
     */
    private static $instance = null;

    /**
     * Constructor
     *
     * @param string $root The root directory for templates
     * @param string $unknowns Policy for handling unknown variables
     */
    public function __construct($root = ".", $unknowns = "remove")
    {
        if ($root == '.' && defined('PHPGW_APP_TPL')) {
            $root = PHPGW_APP_TPL;
        }
        
        $this->serverSettings = Settings::getInstance()->get('server');
        
        $this->set_root($root);
        $this->set_unknowns($unknowns);
        
        // Initialize Twig with the template root
        $this->loader = new FilesystemLoader($this->root);
        $this->twig = new Environment($this->loader, [
            'debug' => ($this->debug > 0),
            'cache' => $this->serverSettings['temp_dir'] . '/twig_cache',
            'auto_reload' => true,
        ]);
        
        // Add support for the legacy block system
        $this->twig->addFunction(new TwigFunction('legacy_block', [$this, 'renderBlock']));
    }

    /**
     * Singleton instance getter
     *
     * @param string $root The root directory for templates
     * @param string $unknowns Policy for handling unknown variables
     * @return TwigTemplate
     */
    public static function getInstance($root = ".", $unknowns = "remove"): TwigTemplate
    {
        if (self::$instance === null) {
            self::$instance = new self($root, $unknowns);
        }
        return self::$instance;
    }

    /**
     * Sets the template directory
     *
     * @param string $root The template directory path
     * @param int $attempt The number of attempts to set the root
     * @return bool Success status
     */
    public function set_root($root = null, $attempt = 0)
    {
        if (is_null($root)) {
            $flags = \App\modules\phpgwapi\services\Settings::getInstance()->get('flags');
            $root = SRC_ROOT_PATH . $flags['currest_app'] . '/Templates';
        }

        if (!is_dir($root)) {
            if ($attempt == 1) {
                $this->halt("set_root: $root is not a directory.");
                return false;
            } else {
                $new_root = preg_replace("/\/{$this->serverSettings['template_set']}\$/", '/base', $root);
                $this->set_root($new_root, 1);
            }
        }

        $this->root = $root;

        // Update Twig loader if it exists
        if (isset($this->loader)) {
            $this->loader->setPaths([$this->root]);
        }

        return true;
    }

    /**
     * Sets policy for dealing with unresolved variable names
     *
     * @param string $unknowns Policy ("remove", "keep", or "comment")
     * @return void
     */
    public function set_unknowns($unknowns = "remove")
    {
        $this->unknowns = $unknowns;
    }

    /**
     * Maps a variable to a template file
     *
     * @param string|array $varname Variable name or array of variable=>file mappings
     * @param string $filename The template filename (if $varname is a string)
     * @return bool Success status
     */
    public function set_file($varname, $filename = "")
    {
        if (!is_array($varname)) {
            if ($filename == "") {
                $this->halt("set_file: For varname $varname filename is empty.");
                return false;
            }
            $this->file[$varname] = $this->filename($filename);
        } else {
            foreach ($varname as $v => $f) {
                if ($f == "") {
                    $this->halt("set_file: For varname $v filename is empty.");
                    return false;
                }
                $this->file[$v] = $this->filename($f);
            }
        }
        return true;
    }

    /**
     * Extracts a block from a parent template and saves it as a variable
     *
     * @param string $parent Parent template name
     * @param string $varname Block name to extract
     * @param string $name Variable name to save the block as (defaults to $varname)
     * @return bool Success status
     */
    public function set_block($parent, $varname, $name = "")
    {
        if (!$this->loadfile($parent)) {
            $this->halt("set_block: unable to load $parent.");
            return false;
        }
        
        if ($name == "") {
            $name = $varname;
        }

        $contents = $this->get_var($parent);
        
        // Extract the block content using regex (similar to legacy template)
        $reg = "/[ \t]*<!--\s+BEGIN $varname\s+-->\s*?\n?(\s*.*?\n?)\s*<!--\s+END $varname\s+-->\s*?\n?/sm";
        
        preg_match_all($reg, $contents, $matches);
        
        if (!isset($matches[1][0])) {
            $this->halt("set_block: unable to set block $varname.");
            return false;
        }
        
        // Store the block content for later use
        $this->set_var($varname, $matches[1][0]);
        
        // Replace the block in the parent with a placeholder
        $contents = preg_replace($reg, "{{ legacy_block('$name') }}", $contents);
        $this->set_var($parent, $contents);
        
        // Remember this block for the renderBlock function
        $this->blocks[$name] = $varname;
        
        return true;
    }

    /**
     * Sets the value of a variable
     *
     * @param string|array $varname Variable name or array of variable=>value pairs
     * @param string $value The value to set (if $varname is a string)
     * @param bool $append Whether to append the value to existing content
     * @return void
     */
    public function set_var($varname, $value = "", $append = false)
    {
        if (!is_array($varname)) {
            if (!empty($varname)) {
                if ($append && isset($this->varvals[$varname])) {
                    $this->varvals[$varname] .= $value;
                } else {
                    $this->varvals[$varname] = $value;
                }
            }
        } else {
            foreach ($varname as $k => $v) {
                if (!empty($k)) {
                    if ($append && isset($this->varvals[$k])) {
                        $this->varvals[$k] .= $v;
                    } else {
                        $this->varvals[$k] = $v;
                    }
                }
            }
        }
    }

    /**
     * Clears the value of a variable
     *
     * @param string|array $varname Variable name or array of variable names
     * @return void
     */
    public function clear_var($varname)
    {
        if (!is_array($varname)) {
            if (!empty($varname)) {
                $this->set_var($varname, "");
            }
        } else {
            foreach ($varname as $v) {
                if (!empty($v)) {
                    $this->set_var($v, "");
                }
            }
        }
    }

    /**
     * Completely unsets a variable
     *
     * @param string|array $varname Variable name or array of variable names
     * @return void
     */
    public function unset_var($varname)
    {
        if (!is_array($varname)) {
            if (!empty($varname)) {
                unset($this->varvals[$varname]);
            }
        } else {
            foreach ($varname as $v) {
                if (!empty($v)) {
                    unset($this->varvals[$v]);
                }
            }
        }
    }

    /**
     * Gets the current value of a variable
     *
     * @param string|array $varname Variable name or array of variable names
     * @return string|array The value(s) of the variable(s)
     */
    public function get_var($varname)
    {
        if (!is_array($varname)) {
            return isset($this->varvals[$varname]) ? $this->varvals[$varname] : "";
        } else {
            $result = array();
            foreach ($varname as $v) {
                $result[$v] = isset($this->varvals[$v]) ? $this->varvals[$v] : "";
            }
            return $result;
        }
    }

    /**
     * Substitutes variables in a template
     *
     * @param string $varname The template variable name
     * @return string The template with variables substituted
     */
    public function subst($varname)
    {
        if (!$this->loadfile($varname)) {
            $this->halt("subst: unable to load $varname.");
            return false;
        }

        // For Twig templates, we can use a Twig template string
        $template_content = $this->get_var($varname);
        
        // Special case for complete twig templates
        if (strpos($template_content, '{% extends') === 0 || 
            strpos($template_content, '{# twig #}') === 0) {
            // This is a full Twig template with extends or marked with {# twig #}
            try {
                $template = $this->twig->createTemplate($template_content);
                return $template->render($this->varvals);
            } catch (\Exception $e) {
                $this->halt("Twig template error: " . $e->getMessage());
                return false;
            }
        }

        // Legacy template - use simple variable replacement
        // Convert to Twig syntax for simple variable replacement
        $twig_content = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '{{ $1 }}', $template_content);
        
        try {
            $template = $this->twig->createTemplate($twig_content);
            return $template->render($this->varvals);
        } catch (\Exception $e) {
            $this->halt("Twig template error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Print substitution results
     *
     * @param string $varname The variable name
     * @return false
     */
    public function psubst($varname)
    {
        print $this->subst($varname);
        return false;
    }

    /**
     * Parse a template and store result in another variable
     *
     * @param string $target Target variable name
     * @param string|array $varname Source template name or array of names
     * @param bool $append Whether to append to target
     * @return string The parsed content
     */
    public function parse($target, $varname, $append = false)
    {
        if (!is_array($varname)) {
            $str = $this->subst($varname);
            if ($append) {
                $this->set_var($target, $this->get_var($target) . $str);
            } else {
                $this->set_var($target, $str);
            }
        } else {
            foreach ($varname as $v) {
                $str = $this->subst($v);
                if ($append) {
                    $this->set_var($target, $this->get_var($target) . $str);
                } else {
                    $this->set_var($target, $str);
                }
            }
        }

        return $this->get_var($target);
    }

    /**
     * Print the parsed content
     *
     * @param string $target Target variable name
     * @param string|array $varname Source template name or array of names
     * @param bool $append Whether to append to target
     * @return false
     */
    public function pparse($target, $varname, $append = false)
    {
        print $this->finish($this->parse($target, $varname, $append));
        return false;
    }

    /**
     * Get all variables
     *
     * @return array Array of all variables and their values
     */
    public function get_vars()
    {
        return $this->varvals;
    }

    /**
     * Helper to finish template by handling undefined variables
     *
     * @param string $str The template string
     * @return string The finished template
     */
    public function finish($str)
    {
        // Apply the undefined variable policy
        switch ($this->unknowns) {
            case "keep":
                // Do nothing - keep undefined variables
                break;

            case "remove":
                // Remove any remaining variable placeholders
                $str = preg_replace('/\{\{[^}]*\}\}/', '', $str);
                break;

            case "comment":
                // Replace undefined variables with comments
                $str = preg_replace('/\{\{([^}]*)\}\}/', '<!-- Template variable $1 undefined -->', $str);
                break;
        }

        return $str;
    }

    /**
     * Get the finished value of a variable
     *
     * @param string $varname Variable name
     * @return string The finished variable value
     */
    public function get($varname)
    {
        return $this->finish($this->get_var($varname));
    }

    /**
     * Print the finished value of a variable
     *
     * @param string $varname Variable name
     * @return void
     */
    public function p($varname)
    {
        print $this->finish($this->get_var($varname));
    }

    /**
     * Convert a relative path to an absolute path
     *
     * @param string $filename The filename to convert
     * @param string $root The root directory (defaults to $this->root)
     * @param int $attempt Number of attempts so far
     * @return string The absolute path
     */
    protected function filename($filename, $root = '', $attempt = 0)
    {
        if ($root == '') {
            $root = $this->root;
        }
        
        if (substr($filename, 0, 1) != '/') {
            $new_filename = $root . '/' . $filename;
        } else {
            $new_filename = $filename;
        }

        // Check if file exists, try base template if not
        if (!file_exists($new_filename)) {
            if ($attempt == 1) {
                $this->halt("filename: file $new_filename does not exist.");
            } else {
                $new_root = preg_replace("/\/templates\/{$this->serverSettings['template_set']}\$/", '/templates/base', $root);
                $new_filename = $this->filename($filename, $new_root, 1);
            }
        }
        
        return $new_filename;
    }

    /**
     * Loads a file if not already loaded
     *
     * @param string $varname The variable name associated with the file
     * @return bool Success status
     */
    protected function loadfile($varname)
    {
        if (!isset($this->file[$varname])) {
            // Not a file variable
            return true;
        }

        if (isset($this->varvals[$varname])) {
            // Already loaded
            return true;
        }

        $filename = $this->file[$varname];
        
        // Load the file contents
        $str = @file_get_contents($filename);
        if (empty($str)) {
            $this->halt("loadfile: While loading $varname, $filename does not exist or is empty.");
            return false;
        }

        // Add filename comments if enabled
        if ($this->filename_comments) {
            $str = "<!-- START FILE $filename -->\n$str<!-- END FILE $filename -->\n";
        }

        // Set the variable value to the file contents
        $this->set_var($varname, $str);
        
        return true;
    }

    /**
     * Render a block (used by Twig templates to render legacy blocks)
     *
     * @param string $name The block name
     * @return string The rendered block
     */
    public function renderBlock($name)
    {
        // If this block has a mapping from set_block, use that
        $varname = isset($this->blocks[$name]) ? $this->blocks[$name] : $name;
        return isset($this->varvals[$varname]) ? $this->varvals[$varname] : '';
    }

    /**
     * Error handling
     *
     * @param string $msg Error message
     * @return false
     */
    public function halt($msg)
    {
        $this->last_error = $msg;

        if ($this->halt_on_error != "no") {
            $this->haltmsg($msg);
        }

        if ($this->halt_on_error == "yes") {
            die("<b>Halted.</b>");
        }

        return false;
    }

    /**
     * Display an error message
     *
     * @param string $msg Error message
     * @return void
     */
    public function haltmsg($msg)
    {
        $msg = str_replace(SRC_ROOT_PATH, '/path/to/portico', $msg);
        trigger_error("Template Error: {$msg}", E_USER_ERROR);
    }

    /**
     * Shortcut for finish(parse())
     *
     * @param string $target Target variable
     * @param string|array $handle Template variable(s)
     * @param bool $append Whether to append
     * @return string The finished parsed template
     */
    public function fp($target, $handle, $append = false)
    {
        return $this->finish($this->parse($target, $handle, $append));
    }

    /**
     * Shortcut to print finish(parse())
     *
     * @param string $target Target variable
     * @param string|array $handle Template variable(s)
     * @param bool $append Whether to append
     * @return void
     */
    public function pfp($target, $handle, $append = false)
    {
        echo $this->finish($this->parse($target, $handle, $append));
    }
}