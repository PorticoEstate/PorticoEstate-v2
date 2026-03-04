# Twig Template System in this Project

## Overview

This project uses [Twig](https://twig.symfony.com/) as a modern template engine, gradually replacing the legacy template system. Twig offers several advantages:

- Clear separation between presentation and business logic
- Template inheritance and reuse
- Automatic output escaping for better security
- A rich set of built-in filters and functions
- Extension possibilities

## Basic Twig Syntax

### Variables

To output a variable in Twig:

```twig
{{ variable_name }}
```

### Control Structures

#### Conditionals

```twig
{% if condition %}
  <!-- content -->
{% elseif another_condition %}
  <!-- alternative content -->
{% else %}
  <!-- fallback content -->
{% endif %}
```

#### Loops

```twig
{% for item in items %}
  {{ item.name }}
{% endfor %}
```

### Template Inheritance

#### Base Template (layout.html.twig)

```twig
<!DOCTYPE html>
<html>
<head>
  <title>{% block title %}Default Title{% endblock %}</title>
</head>
<body>
  <header>{% block header %}Default Header{% endblock %}</header>
  <main>{% block content %}{% endblock %}</main>
  <footer>{% block footer %}Default Footer{% endblock %}</footer>
</body>
</html>
```

#### Child Template

```twig
{% extends "layout.html.twig" %}

{% block title %}My Page Title{% endblock %}

{% block content %}
  <h1>Hello World</h1>
{% endblock %}
```

## Project-Specific Twig Integration

### Directory Structure

Twig templates are stored in module-specific template directories:

```
src/modules/[module_name]/templates/base/*.html.twig
```

In some cases, there may be template variations in a theme-specific folder:

```
src/modules/[module_name]/templates/[template_set]/*.html.twig
```

### Available Custom Functions

Our Twig implementation includes several custom functions:

#### Translation Function

```twig
{{ lang('Some_text_with_underscores') }}
```

This function automatically:
1. Replaces underscores with spaces
2. Translates the text using the system's language files
3. Outputs the result

#### Replace Underscores Filter

```twig
{{ 'Some_text_with_underscores'|replace_underscores }}
```

This filter converts underscores to spaces without translation.

### Blocks System

Our Twig implementation supports two block rendering approaches:

#### 1. Native Twig Blocks

Define blocks in templates:

```twig
{% block row %}
  <tr class="{{ tr_class }}">
    <td class="center">{{ label }}</td>
    <td class="center">{{ value|raw }}</td>
  </tr>
{% endblock %}
```

Render them in PHP:

```php
$twig = Twig::getInstance();
$html = $twig->renderBlock('template.html.twig', 'row', [
    'tr_class' => 'odd',
    'label' => 'Field Label',
    'value' => 'Field Value'
], 'module_namespace');
```

#### 2. Legacy Block Support

For backwards compatibility, we also support rendering legacy template blocks:

```twig
{{ legacy_block('block_name') }}
```

### Using Twig in PHP Controllers

#### Direct Usage with the Twig Service

```php
use App\modules\phpgwapi\services\Twig;

// Inside your controller method:
$twig = Twig::getInstance();
$html = $twig->render('template.html.twig', [
    'variable1' => 'value1',
    'variable2' => 'value2',
], 'module_namespace');

// Output the result
echo $html;
```

#### Using Template Helper

```php
use App\helpers\Template;

// Inside your controller method:
$html = Template::renderTwig('template.html.twig', [
    'variable1' => 'value1',
    'variable2' => 'value2',
], 'module_namespace');

// Output the result
echo $html;
```

### Migrating from Legacy Templates

When migrating from the legacy template system to Twig:

1. Create a new `.html.twig` file based on the original `.tpl` file
2. Convert template syntax:
   - Replace `{variable}` with `{{ variable }}`
   - Replace `<!-- BEGIN block_name -->..<!-- END block_name -->` with `{% block block_name %}..{% endblock %}`
   - Replace PHP logic with Twig conditionals and loops
3. Update your controller to use Twig rendering:
   ```php
   // Legacy approach:
   $template = new Template();
   $template->set_file('template_file', 'template.tpl');
   $template->set_var('variable', 'value');
   $template->pparse('output', 'template_file');
   
   // Twig approach:
   echo Template::renderTwig('template.html.twig', ['variable' => 'value'], 'module_namespace');
   ```

### Compatibility Layer

For gradual migration, we provide two compatibility approaches:

#### TwigTemplate Class

A drop-in replacement for the legacy Template class that uses Twig under the hood:

```php
use App\helpers\twig\TwigTemplate;

$template = new TwigTemplate();
$template->set_file('template_file', 'template.tpl');
$template->set_var('variable', 'value');
$template->pparse('output', 'template_file');
```

#### Template Helper for Converting Variables

```php
use App\helpers\twig\TwigTemplateHelper;

$vars = [
    'title' => 'Page Title',
    'items' => ['Item 1', 'Item 2', 'Item 3']
];

$blocks = [
    'items_block' => 'items'
];

$twigVars = TwigTemplateHelper::convertVarsForTwig($vars, $blocks);
```

## Best Practices

1. **Prefer Twig blocks over legacy blocks** for new development
2. **Use namespaces** to organize templates by module
3. **Apply template inheritance** for consistent layouts
4. **Use the `raw` filter sparingly** - Twig auto-escapes by default for security
5. **Consider caching** for performance-critical templates
6. **Keep presentation logic in templates** and business logic in PHP
7. **Always translate user-facing strings** using the `lang()` function

## Debugging Twig Templates

### Debug Output

```twig
{{ dump(variable) }}
```

### Adding Comments

```twig
{# This is a comment that won't be rendered in the output #}
```

### Checking Template Paths

To see which template paths are registered:

```php
$loader = Twig::getInstance()->getEnvironment()->getLoader();
$namespaces = $loader->getNamespaces();
foreach ($namespaces as $namespace) {
    $paths = $loader->getPaths($namespace);
    // Output or log these paths
}
```

## Cache Management

Twig templates are cached in the `/var/www/html/cache/twig` directory. If you make changes to templates and don't see them reflected:

1. Clear the cache directory manually
2. Set `'auto_reload' => true` in the Twig environment (already done in this project)
3. Consider disabling cache during development with `'cache' => false`