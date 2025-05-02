# Template Conversion Rules: TPL to Twig

This document outlines the rules and patterns for converting legacy TPL template files to Twig templates. Following these guidelines will ensure consistent conversion across the application.

## Basic Syntax Transformations

### 1. Variable Syntax
- Convert `{variable}` to `{{ variable }}`
- For variables that contain HTML: `{variable}` to `{{ variable|raw }}`
- Example:
  ```
  // TPL
  <div>{content}</div>
  
  // Twig
  <div>{{ content|raw }}</div>
  ```

### 2. Translation Strings
- Convert `{lang_keyname}` to `{{ lang('keyname') }}`
- Convert any variables with prefix 'lang_' using the lang function
- Example:
  ```
  // TPL
  <label>{lang_username}</label>
  
  // Twig
  <label>{{ lang('username') }}</label>
  ```

### 3. Block Structure
- Convert `<!-- BEGIN blockname -->...<!-- END blockname -->` to `{% block blockname %}...{% endblock %}`
- Example:
  ```
  // TPL
  <!-- BEGIN form -->
  <form method="post">...</form>
  <!-- END form -->
  
  // Twig
  {% block form %}
  <form method="post">...</form>
  {% endblock %}
  ```

### 4. Template Headers
- Add a Twig comment at the top: `{# Template name #}`
- Example:
  ```
  // Twig
  {# User profile template #}
  ```

### 5. Conditional Statements
- Convert `<!-- IF condition -->` to `{% if condition %}`
- Convert `<!-- ENDIF -->` to `{% endif %}`
- Example:
  ```
  // TPL
  <!-- IF is_admin -->
  <div class="admin-panel">...</div>
  <!-- ENDIF -->
  
  // Twig
  {% if is_admin %}
  <div class="admin-panel">...</div>
  {% endif %}
  ```

### 6. Loops
- Convert `<!-- LOOP items -->` to `{% for item in items %}`
- Convert `<!-- ENDLOOP -->` to `{% endfor %}`
- Example:
  ```
  // TPL
  <!-- LOOP users -->
  <li>{user_name}</li>
  <!-- ENDLOOP -->
  
  // Twig
  {% for user in users %}
  <li>{{ user.name }}</li>
  {% endfor %}
  ```

## Special Cases and HTML Attributes

### 7. Raw HTML Output
- Add the `|raw` filter to variables that might contain HTML: options, select, rows, input, form_action, value
- Example:
  ```
  // TPL
  {form_html}
  
  // Twig
  {{ form_html|raw }}
  ```

### 8. Form Labels and Translations
- Convert HTML attributes with text: `label="Text"` to `label="{{ lang('Text') }}"`
- Convert visible text patterns: `>Label Text<` to `>{{ lang('Label_Text') }}<`
- Clean up missed language variables: `{{ lang_key }}` to `{{ lang('key') }}`
- Example:
  ```
  // TPL
  <label for="username">Username:</label>
  
  // Twig
  <label for="username">{{ lang('Username') }}:</label>
  ```

### 9. Table Headers
- Convert table headers with specific pattern to Twig syntax
- Add section comments for better readability: `{# Section name #}`
- Example:
  ```
  // TPL
  <th>{lang_header_name}</th>
  
  // Twig
  <th>{{ lang('header_name') }}</th>
  ```

## Implementation Conversion

### 10. Template Loading
- Replace calls to `$this->template->set_file()` with Twig template loader code
- Use `TwigTemplateHelper::createTwigTemplate()` instead of `new Template()`
- Example:
  ```php
  // Legacy PHP
  $this->template = new Template($app->rootdir . '/templates');
  $this->template->set_file('form', 'user_form.tpl');
  
  // Twig PHP
  $this->template = TwigTemplateHelper::createTwigTemplate($app->rootdir . '/templates');
  $this->template->set_file('form', 'user_form.html.twig');
  ```

### 11. Block Handling
- Replace `$this->template->set_block()` with Twig parent/child template inheritance 
- Use `{% extends 'base.html.twig' %}` for template inheritance
- Example:
  ```php
  // Legacy PHP
  $this->template->set_block('form', 'row', 'rows');
  
  // Twig PHP - No need for this in controller, handled in templates
  // In template: {% block row %}...{% endblock %}
  ```

### 12. Variable Assignment
- Replace `$this->template->set_var()` with passing variables to Twig's render method
- Use `TwigTemplateHelper::convertVarsForTwig()` to format variables correctly
- Example:
  ```php
  // Legacy PHP
  $this->template->set_var('header', 'Page Title');
  
  // Twig PHP
  $vars = ['header' => 'Page Title'];
  $vars = TwigTemplateHelper::convertVarsForTwig($vars);
  ```

### 13. Template Rendering
- Replace `$this->template->parse()` with Twig's `render()` method
- Replace `$this->template->fp()` with direct rendering
- Example:
  ```php
  // Legacy PHP
  $content = $this->template->parse('output', 'form');
  $this->template->fp('output', 'form'); // or echo to output
  
  // Twig PHP
  $content = $this->twig->render('form.html.twig', $vars);
  echo $content; // or return $content
  ```

## File Structure Changes

### 14. File Extensions
- Rename `.tpl` files to `.html.twig`
- Store in same directory structure for compatibility
- Example:
  ```
  user_profile.tpl → user_profile.html.twig
  ```

### 15. File Path References
- Update file references in PHP code to point to `.html.twig` files
- Use `TwigTemplateHelper::getTwigTemplate()` to locate proper template files
- Example:
  ```php
  // Legacy PHP
  $template_file = 'user_form.tpl';
  
  // Twig PHP
  $template_file = 'user_form.html.twig';
  // Or use helper:
  $template_file = TwigTemplateHelper::getTwigTemplate('user_form.tpl', $templateDir);
  ```

## Special Twig Features to Utilize

### 16. Template Inheritance
- Use `{% extends 'base.html.twig' %}` at the top of child templates
- Define blocks with `{% block name %}` that override parent blocks
- Example:
  ```twig
  {# Child template #}
  {% extends 'layout.html.twig' %}
  
  {% block content %}
    <h1>{{ page_title }}</h1>
    <p>{{ page_content }}</p>
  {% endblock %}
  ```

### 17. Include Statements
- Replace PHP includes with `{% include 'template.html.twig' %}`
- Use `{% include 'template.html.twig' with {'var': value} %}` for variables
- Example:
  ```twig
  {% include 'partials/header.html.twig' %}
  {% include 'partials/sidebar.html.twig' with {'active_menu': 'dashboard'} %}
  ```

### 18. Filters and Functions
- Use `|escape` (or nothing, as it's default) for escaping output
- Use `|raw` for unescaped output
- Use `|date` for date formatting
- Example:
  ```twig
  {{ username }} {# automatically escaped #}
  {{ html_content|raw }} {# not escaped #}
  {{ created_date|date('Y-m-d') }} {# formatted date #}
  ```

### 19. Form Integration
- Use Twig's form features for rendering form elements
- Add proper CSRF protection using Twig functions
- Example:
  ```twig
  <form method="post" action="{{ form_action }}">
    <input type="hidden" name="csrf_token" value="{{ csrf_token() }}" />
    {{ form_widget(form) }}
    <button type="submit">Submit</button>
  </form>
  ```

### 20. Legacy Support
- Mark full Twig templates with `{# twig #}` at the beginning to indicate no further conversion needed
- Use `legacy_block()` function to maintain compatibility with old block system
- Example:
  ```twig
  {# twig #}
  {% extends 'base.html.twig' %}
  
  {# For legacy support #}
  {{ legacy_block('some_old_block') }}
  ```

## Variable Treatment

### 21. Array Access
- Convert PHP array access `$array['key']` to Twig's dot notation `array.key`
- For numeric indices, keep bracket notation: `array[0]`
- Example:
  ```twig
  {# Access object properties or array keys #}
  {{ user.name }}
  {{ users[0].name }}
  ```

### 22. Variable Testing
- Replace PHP empty checks with Twig's `is empty` or `is not empty`
- Use `is defined` to check if variables exist
- Example:
  ```twig
  {% if username is defined and username is not empty %}
    <p>Hello, {{ username }}!</p>
  {% else %}
    <p>Hello, guest!</p>
  {% endif %}
  ```

## Example of Full Template Conversion

### Legacy TPL Template:
```html
<!-- BEGIN header -->
<h1>{page_title}</h1>
<!-- END header -->

<!-- BEGIN content -->
<div class="content">
  <!-- IF has_items -->
  <ul>
    <!-- LOOP items -->
    <li>{item_name} - {item_desc}</li>
    <!-- ENDLOOP -->
  </ul>
  <!-- ENDIF -->
  {page_content}
</div>
<!-- END content -->

<!-- BEGIN footer -->
<div class="footer">{lang_copyright}</div>
<!-- END footer -->
```

### Converted Twig Template:
```twig
{# Page template #}

{% block header %}
<h1>{{ page_title }}</h1>
{% endblock %}

{% block content %}
<div class="content">
  {% if has_items %}
  <ul>
    {% for item in items %}
    <li>{{ item.name }} - {{ item.desc }}</li>
    {% endfor %}
  </ul>
  {% endif %}
  {{ page_content|raw }}
</div>
{% endblock %}

{% block footer %}
<div class="footer">{{ lang('copyright') }}</div>
{% endblock %}
```

## PHP Controller Conversion

### Legacy PHP:
```php
$this->template = new Template($this->rootdir);
$this->template->set_file('tpl', 'page.tpl');
$this->template->set_block('tpl', 'header', 'header_section');
$this->template->set_block('tpl', 'content', 'content_section');
$this->template->set_block('tpl', 'footer', 'footer_section');

$this->template->set_var('page_title', 'Welcome Page');
$this->template->set_var('has_items', true);
$this->template->set_var('page_content', '<p>Welcome to our site!</p>');

// For loop items
$items = [
    ['item_name' => 'Item 1', 'item_desc' => 'Description 1'],
    ['item_name' => 'Item 2', 'item_desc' => 'Description 2']
];

foreach ($items as $item) {
    $this->template->set_var('item_name', $item['item_name']);
    $this->template->set_var('item_desc', $item['item_desc']);
    $this->template->parse('items', 'item', true);
}

$this->template->parse('header_section', 'header');
$this->template->parse('content_section', 'content');
$this->template->parse('footer_section', 'footer');
$this->template->fp('output', 'tpl');
```

### Converted Twig PHP:
```php
$this->template = TwigTemplateHelper::createTwigTemplate($this->rootdir);

$items = [
    ['name' => 'Item 1', 'desc' => 'Description 1'],
    ['name' => 'Item 2', 'desc' => 'Description 2']
];

$vars = [
    'page_title' => 'Welcome Page',
    'has_items' => true,
    'items' => $items,
    'page_content' => '<p>Welcome to our site!</p>'
];

// Convert variables for Twig
$twigVars = TwigTemplateHelper::convertVarsForTwig($vars);

// Render the template
echo $this->template->twig->render('page.html.twig', $twigVars);
```