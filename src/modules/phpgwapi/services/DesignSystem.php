<?php

namespace App\modules\phpgwapi\services;

use App\modules\phpgwapi\services\Settings;

/**
 * Design System integration service
 * Manages loading of Digdir Designsystemet alongside existing Bootstrap
 */
class DesignSystem
{
    private static $instance;
    private $serverSettings;
    private $userSettings;
    private $useDesignsystemet = false;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->serverSettings = Settings::getInstance()->get('server');
        $this->userSettings = Settings::getInstance()->get('user');
        
        // Check if using digdir template
        $this->useDesignsystemet = ($this->serverSettings['template_set'] === 'digdir');
    }

    /**
     * Get CSS files for the design system
     * 
     * @return array Array of CSS file paths
     */
    public function getStylesheets(): array
    {
        $stylesheets = [];

        if ($this->useDesignsystemet) {
            // Digdir Designsystemet CSS
            $stylesheets[] = '/node_modules/@digdir/designsystemet-css/dist/index.css';
            
            // Add compatibility layer
            $stylesheets[] = '/phpgwapi/templates/digdir/css/designsystemet-compat.css';
        } else {
            // Fallback to Bootstrap
            $stylesheets[] = '/phpgwapi/js/bootstrap5/vendor/twbs/bootstrap/dist/css/bootstrap.min.css';
        }

        return $stylesheets;
    }

    /**
     * Get JavaScript files for the design system
     * 
     * @return array Array of JS file paths
     */
    public function getScripts(): array
    {
        $scripts = [];

        if ($this->useDesignsystemet) {
            // Digdir Designsystemet Web Components
            $scripts[] = '/node_modules/@digdir/designsystemet-web/dist/index.esm.js';
        } else {
            // Bootstrap JS (handled elsewhere)
        }

        return $scripts;
    }

    /**
     * Check if Designsystemet is enabled
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->useDesignsystemet;
    }

    /**
     * Get component wrapper for compatibility
     * 
     * @param string $component Component name
     * @param array $props Component properties
     * @return string HTML/Web Component markup
     */
    public function component(string $component, array $props = []): string
    {
        if ($this->useDesignsystemet) {
            return $this->renderDesignsystemComponent($component, $props);
        } else {
            return $this->renderBootstrapComponent($component, $props);
        }
    }

    /**
     * Render Designsystemet web component
     */
    private function renderDesignsystemComponent(string $component, array $props): string
    {
        $componentMap = [
            'button' => 'ds-button',
            'input' => 'ds-textfield',
            'select' => 'ds-select',
            'modal' => 'ds-modal',
            'alert' => 'ds-alert',
            'card' => 'ds-card',
            'chip' => 'ds-chip',
            'accordion' => 'ds-accordion',
            'tabs' => 'ds-tabs',
            'table' => 'ds-table',
        ];

        $tag = $componentMap[$component] ?? "ds-{$component}";
        $attributes = $this->buildAttributes($props);
        $content = $props['content'] ?? '';

        return "<{$tag} {$attributes}>{$content}</{$tag}>";
    }

    /**
     * Render Bootstrap component (fallback)
     */
    private function renderBootstrapComponent(string $component, array $props): string
    {
        switch ($component) {
            case 'button':
                $variant = $props['variant'] ?? 'primary';
                $size = isset($props['size']) ? " btn-{$props['size']}" : '';
                $disabled = !empty($props['disabled']) ? ' disabled' : '';
                $class = isset($props['class']) ? " {$props['class']}" : '';
                $type = $props['type'] ?? 'button';
                $content = $props['content'] ?? '';
                
                return sprintf(
                    '<button type="%s" class="btn btn-%s%s%s"%s>%s</button>',
                    htmlspecialchars($type),
                    htmlspecialchars($variant),
                    $size,
                    $class,
                    $disabled,
                    $content
                );
            
            case 'alert':
                $variant = $props['variant'] ?? 'info';
                $dismissible = !empty($props['dismissible']);
                $class = isset($props['class']) ? " {$props['class']}" : '';
                $content = $props['content'] ?? '';
                
                $html = sprintf(
                    '<div class="alert alert-%s%s%s" role="alert">%s',
                    htmlspecialchars($variant),
                    $dismissible ? ' alert-dismissible fade show' : '',
                    $class,
                    $content
                );
                
                if ($dismissible) {
                    $html .= '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                }
                
                $html .= '</div>';
                return $html;
            
            case 'card':
                $class = isset($props['class']) ? " {$props['class']}" : '';
                $title = $props['title'] ?? '';
                $content = $props['content'] ?? '';
                
                $html = '<div class="card' . $class . '">';
                if ($title) {
                    $html .= '<div class="card-header">' . $title . '</div>';
                }
                $html .= '<div class="card-body">' . $content . '</div>';
                $html .= '</div>';
                
                return $html;
            
            default:
                return '';
        }
    }

    /**
     * Build HTML attributes from props array
     */
    private function buildAttributes(array $props, array $exclude = []): string
    {
        $attributes = [];
        $excludeKeys = array_merge(['content', 'variant', 'dismissible'], $exclude);
        
        foreach ($props as $key => $value) {
            if (in_array($key, $excludeKeys)) {
                continue;
            }
            
            if (is_bool($value)) {
                if ($value) {
                    $attributes[] = htmlspecialchars($key);
                }
            } else {
                $attributes[] = sprintf('%s="%s"', htmlspecialchars($key), htmlspecialchars($value));
            }
        }
        return implode(' ', $attributes);
    }

    /**
     * Get design tokens for the current theme
     * 
     * @return array Design tokens
     */
    public function getDesignTokens(): array
    {
        if (!$this->useDesignsystemet) {
            return [];
        }

        return [
            'spacing' => [
                '1' => '0.25rem',
                '2' => '0.5rem',
                '3' => '0.75rem',
                '4' => '1rem',
                '5' => '1.25rem',
                '6' => '1.5rem',
                '7' => '2rem',
                '8' => '2.5rem',
                '9' => '3rem',
                '10' => '4rem',
            ],
            'colors' => [
                'primary' => '#0062ba',
                'secondary' => '#6c757d',
                'success' => '#198754',
                'danger' => '#dc3545',
                'warning' => '#ffc107',
                'info' => '#0dcaf0',
            ],
        ];
    }
}
