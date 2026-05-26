# Digdir Designsystemet Template for PorticoEstate

This template integrates the [Digdir Designsystemet](https://www.designsystemet.no/) (Norwegian Design System) into PorticoEstate, providing a modern, accessible, and standards-compliant UI framework.

## Overview

The `digdir` template is a complete implementation of the Digdir Designsystemet within PorticoEstate, featuring:

- **Web Components**: Modern, reusable UI components
- **Design Tokens**: Consistent spacing, colors, and typography
- **Accessibility**: WCAG 2.1 AA compliant by default
- **Responsive**: Mobile-first design approach
- **Hybrid Approach**: Seamless fallback to Bootstrap when needed

## Installation

### 1. Enable the Template

Update your PorticoEstate configuration to use the digdir template:

```php
// In your config/header.inc.php or admin settings
$GLOBALS['phpgw_info']['server']['template_set'] = 'digdir';
```

### 2. Install Node Dependencies

The Designsystemet packages are already installed:

```bash
npm install @digdir/designsystemet-css @digdir/designsystemet-web --save
```

### 3. Clear Cache

Clear the Twig cache to ensure templates are regenerated:

```bash
rm -rf /var/www/html/src/cache/twig/*
```

## Architecture

### Components

The digdir template provides a hybrid component system that automatically switches between Designsystemet web components and Bootstrap fallbacks:

#### Core Services

- **`DesignSystem.php`** - Central service managing design system integration
- **`Twig.php`** - Enhanced with design system support and component rendering
- **`TwigTemplate.php`** - Convenience wrapper for quick template rendering

#### Component Templates

Located in `/src/modules/phpgwapi/templates/digdir/components/`:

- `button.twig` - Button component
- `alert.twig` - Alert/notification component
- `card.twig` - Card container component
- `modal.twig` - Modal dialog component

#### CSS Structure

- `designsystemet-compat.css` - Bootstrap-to-Designsystemet compatibility layer
- Standard Bootstrap CSS classes mapped to Designsystemet design tokens

## Usage

### In Twig Templates

#### Using Component Includes

```twig
{# Button component #}
{% include '@components/button.twig' with {
    content: lang('save'),
    variant: 'primary',
    type: 'submit',
    id: 'save-btn'
} %}

{# Alert component #}
{% include '@components/alert.twig' with {
    content: lang('success_message'),
    variant: 'success',
    dismissible: true
} %}

{# Card component #}
{% include '@components/card.twig' with {
    title: lang('user_info'),
    content: user_details,
    footer: action_buttons
} %}

{# Modal component #}
{% include '@components/modal.twig' with {
    id: 'confirmModal',
    title: lang('confirm_action'),
    content: confirmation_message,
    footer: modal_buttons,
    size: 'lg'
} %}
```

#### Using the ds_component Function

```twig
{# Direct component rendering #}
{{ ds_component('button', {
    content: lang('cancel'),
    variant: 'secondary',
    size: 'sm'
}) }}

{# Check if Designsystemet is enabled #}
{% if is_designsystemet %}
    <ds-button variant="primary">{{ lang('save') }}</ds-button>
{% else %}
    <button class="btn btn-primary">{{ lang('save') }}</button>
{% endif %}
```

### In PHP Code

#### Using the DesignSystem Service

```php
use App\modules\phpgwapi\services\DesignSystem;

$designSystem = DesignSystem::getInstance();

// Check if Designsystemet is enabled
if ($designSystem->isEnabled()) {
    // Render a component
    echo $designSystem->component('button', [
        'content' => lang('save'),
        'variant' => 'primary',
        'type' => 'submit'
    ]);
}

// Get design tokens
$tokens = $designSystem->getDesignTokens();
$spacing = $tokens['spacing']['4']; // Returns '1rem'
$primaryColor = $tokens['colors']['primary']; // Returns '#0062ba'
```

#### Using Twig Service

```php
use App\modules\phpgwapi\services\Twig;

$twig = Twig::getInstance();

// Render with design system context
echo $twig->render('my_template.twig', [
    'title' => 'My Page',
    'items' => $data
]);
```

## Design Tokens

The digdir template uses CSS custom properties (variables) for consistent styling:

### Spacing

```css
var(--ds-spacing-1)  /* 0.25rem */
var(--ds-spacing-2)  /* 0.5rem */
var(--ds-spacing-3)  /* 0.75rem */
var(--ds-spacing-4)  /* 1rem */
var(--ds-spacing-5)  /* 1.25rem */
var(--ds-spacing-6)  /* 1.5rem */
var(--ds-spacing-7)  /* 2rem */
var(--ds-spacing-8)  /* 2.5rem */
var(--ds-spacing-9)  /* 3rem */
var(--ds-spacing-10) /* 4rem */
```

### Colors

```css
var(--ds-color-primary)       /* #0062ba */
var(--ds-color-secondary)     /* #6c757d */
var(--ds-color-success)       /* #198754 */
var(--ds-color-danger)        /* #dc3545 */
var(--ds-color-warning)       /* #ffc107 */
var(--ds-color-info)          /* #0dcaf0 */
```

### Typography

```css
var(--ds-font-size-sm)        /* 0.875rem */
var(--ds-font-size-md)        /* 1rem */
var(--ds-font-size-lg)        /* 1.25rem */
var(--ds-font-size-xl)        /* 1.75rem */
var(--ds-font-size-2xl)       /* 2rem */
var(--ds-font-size-3xl)       /* 2.5rem */

var(--ds-font-weight-regular) /* 400 */
var(--ds-font-weight-medium)  /* 500 */
var(--ds-font-weight-semibold)/* 600 */
var(--ds-font-weight-bold)    /* 700 */
```

## Bootstrap Compatibility

The digdir template maintains backward compatibility with Bootstrap classes through the `designsystemet-compat.css` file. This means existing code using Bootstrap classes will continue to work:

```html
<!-- These all work as expected -->
<div class="container">
  <div class="row">
    <div class="col-md-6">
      <button class="btn btn-primary">Click Me</button>
      <div class="alert alert-success">Success!</div>
    </div>
  </div>
</div>
```

## Component Properties

### Button

| Property | Type | Description | Values |
|----------|------|-------------|--------|
| `content` | string | Button text/HTML | Any HTML |
| `variant` | string | Button style | `primary`, `secondary`, `success`, `danger`, `warning`, `info` |
| `size` | string | Button size | `sm`, `lg` |
| `type` | string | Button type | `button`, `submit`, `reset` |
| `disabled` | boolean | Disabled state | `true`, `false` |
| `id` | string | Element ID | Any string |
| `class` | string | Additional CSS classes | Any string |

### Alert

| Property | Type | Description | Values |
|----------|------|-------------|--------|
| `content` | string | Alert message | Any HTML |
| `variant` | string | Alert style | `primary`, `success`, `danger`, `warning`, `info` |
| `dismissible` | boolean | Can be closed | `true`, `false` |
| `id` | string | Element ID | Any string |
| `class` | string | Additional CSS classes | Any string |

### Card

| Property | Type | Description | Values |
|----------|------|-------------|--------|
| `title` | string | Card header | Any HTML |
| `content` | string | Card body | Any HTML |
| `footer` | string | Card footer | Any HTML |
| `id` | string | Element ID | Any string |
| `class` | string | Additional CSS classes | Any string |

### Modal

| Property | Type | Description | Values |
|----------|------|-------------|--------|
| `title` | string | Modal title | Any HTML |
| `content` | string | Modal body | Any HTML |
| `footer` | string | Modal footer | Any HTML |
| `size` | string | Modal size | `sm`, `lg`, `xl` |
| `id` | string | Element ID | Any string |
| `class` | string | Additional CSS classes | Any string |

## Migration Guide

### From Bootstrap Template

1. **Change Template Setting**: Update to use `digdir` template
2. **Test Existing Pages**: Most Bootstrap components work automatically
3. **Update Custom Components**: Replace custom Bootstrap components with Designsystemet equivalents
4. **Review Styling**: Check custom CSS for conflicts
5. **Update JavaScript**: Verify Bootstrap JS dependencies

### Example Migration

**Before (Bootstrap):**
```twig
<button class="btn btn-primary" type="submit">
    {{ lang('save') }}
</button>
```

**After (Digdir):**
```twig
{% include '@components/button.twig' with {
    content: lang('save'),
    variant: 'primary',
    type: 'submit'
} %}
```

Or use the shorthand:
```twig
{{ ds_component('button', {content: lang('save'), variant: 'primary', type: 'submit'}) }}
```

## Customization

### Adding Custom Components

Create new component templates in `/src/modules/phpgwapi/templates/digdir/components/`:

```twig
{# custom-component.twig #}
{% if is_designsystemet %}
    <ds-custom-component {{ attributes }}>
        {{ content|raw }}
    </ds-custom-component>
{% else %}
    <div class="custom-bootstrap-fallback">
        {{ content|raw }}
    </div>
{% endif %}
```

### Extending DesignSystem Service

Add custom component rendering to `DesignSystem.php`:

```php
private function renderDesignsystemComponent(string $component, array $props): string
{
    $componentMap = [
        'button' => 'ds-button',
        'your_component' => 'ds-your-component', // Add your component
    ];
    // ... rest of the method
}
```

### Custom Styling

Create theme-specific CSS in `/src/modules/phpgwapi/templates/digdir/css/`:

```css
/* custom-theme.css */
:root {
    --ds-color-primary: #your-color;
    --ds-spacing-custom: 1.75rem;
}
```

## Browser Support

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

For older browsers, the template gracefully falls back to Bootstrap components.

## Performance

- **CSS**: ~50KB (minified)
- **JavaScript**: ~80KB (minified)
- **Twig Cache**: Enabled by default
- **Component Rendering**: < 1ms per component

## Troubleshooting

### Components Not Rendering

1. Check template setting: `$GLOBALS['phpgw_info']['server']['template_set'] === 'digdir'`
2. Clear Twig cache: `rm -rf /var/www/html/src/cache/twig/*`
3. Verify npm packages: `npm list @digdir/designsystemet-css @digdir/designsystemet-web`

### Styling Issues

1. Check browser console for CSS load errors
2. Verify `designsystemet-compat.css` is loaded
3. Check for CSS conflicts with existing stylesheets

### JavaScript Errors

1. Ensure web components are loaded before use
2. Check for conflicting Bootstrap JavaScript
3. Verify module type in script tags

## Resources

- [Digdir Designsystemet Documentation](https://www.designsystemet.no/)
- [Designsystemet on GitHub](https://github.com/digdir/designsystemet)
- [PorticoEstate Documentation](https://github.com/PorticoEstate/PorticoEstate-v2)

## Contributing

To contribute improvements to the digdir template:

1. Create a feature branch
2. Make your changes
3. Test thoroughly
4. Submit a pull request

## License

This template implementation follows the PorticoEstate license.
Digdir Designsystemet is licensed under the MIT License.

## Changelog

### Version 1.0.0 (2026-02-02)

- Initial implementation of Digdir Designsystemet integration
- Created DesignSystem service for component management
- Added component templates for common UI elements
- Implemented Bootstrap compatibility layer
- Enhanced Twig service with design system support
- Added comprehensive documentation

## Support

For issues specific to the digdir template, please open an issue on the PorticoEstate repository.
For Designsystemet questions, refer to the official Digdir documentation.
