<?php
/**
 * Template Converter - Legacy Template to Twig
 * 
 * This script converts legacy template files (.tpl) to Twig templates (.html.twig)
 */

// Configuration
$sourceDir = 'src/modules/setup/templates/base/';
$targetDir = 'src/modules/setup/templates/base/';

// Create target directory if it doesn't exist
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
    echo "Created directory: $targetDir\n";
}

// Find all .tpl files in source directory
$files = glob($sourceDir . '*.tpl');
echo "Found " . count($files) . " template files to convert.\n";

// Process each file
foreach ($files as $file) {
    $baseName = basename($file, '.tpl');
    $newFileName = $targetDir . $baseName . '.html.twig';
    
    echo "Converting $baseName.tpl to Twig syntax...\n";
    
    // Read template content
    $content = file_get_contents($file);
    
    // Apply transformations
    
    // 1. Handle translation strings first - look for variables with prefix 'lang_'
    $content = preg_replace_callback('/\{lang_([a-zA-Z0-9_]+)\}/', function($matches) {
        return '{{ lang(\'' . $matches[1] . '\') }}';
    }, $content);
    
    // 2. Convert regular variable syntax from {var} to {{ var }}
    $content = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function($matches) {
        // Skip lang_ variables as they were already processed
        if (strpos($matches[1], 'lang_') === 0) {
            return '{' . $matches[1] . '}';
        }
        return '{{ ' . $matches[1] . ' }}';
    }, $content);
    
    // 3. Convert blocks from <!-- BEGIN blockname -->...<!-- END blockname --> to {% block blockname %}...{% endblock %}
    $content = preg_replace_callback('/<!-- BEGIN ([a-zA-Z0-9_]+) -->(.+?)<!-- END \\1 -->/s', function($matches) {
        return '{% block ' . $matches[1] . ' %}' . $matches[2] . '{% endblock %}';
    }, $content);
    
    // 4. Add Twig comment at the top
    $content = "{# " . ucfirst($baseName) . " template #}\n" . $content;
    
    // 5. Convert if statements
    $content = preg_replace('/<!-- IF ([^>]+) -->/', '{% if $1 %}', $content);
    $content = preg_replace('/<!-- ENDIF -->/', '{% endif %}', $content);
    
    // 6. Convert loops
    $content = preg_replace('/<!-- LOOP ([^>]+) -->/', '{% for item in $1 %}', $content);
    $content = preg_replace('/<!-- ENDLOOP -->/', '{% endfor %}', $content);
    
    // 7. Add comments for sections
    $content = preg_replace('/<tr class="th">\s*<td[^>]*>[^<]*<b>([^<]+)<\/b><\/td>\s*<\/tr>/', '<tr class="th">\n        <td colspan="2">&nbsp;<b>{{ lang(\'$1\') }}</b></td>\n    </tr>\n\n    {# $1 section #}', $content);
    
    // 8. Handle common translation patterns in labels - look for common translatable strings
    $translationPatterns = [
        '/>\s*([A-Z][a-zA-Z0-9_ ]+):?\s*<\//' => '> {{ lang(\'$1\') }}: </',
        '/label=["\'](.*?)["\']/' => function($matches) {
            // Don't convert if it already contains Twig syntax
            if (strpos($matches[1], '{{') !== false) {
                return $matches[0];
            }
            // Convert label text to lang() function call
            $text = str_replace(' ', '_', trim($matches[1]));
            return 'label="{{ lang(\'' . $text . '\') }}"';
        }
    ];
    
    foreach ($translationPatterns as $pattern => $replacement) {
        if (is_callable($replacement)) {
            $content = preg_replace_callback($pattern, $replacement, $content);
        } else {
            $content = preg_replace($pattern, $replacement, $content);
        }
    }
    
    // 9. Clean up any missed language variables in double curly braces
    $content = preg_replace('/\{\{\s*lang_([a-zA-Z0-9_]+)\s*\}\}/', '{{ lang(\'$1\') }}', $content);
    
    // 10. Fix raw HTML output for select options and other dynamic content
    $content = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function($matches) {
        $var = $matches[1];
        // List of variables that might contain HTML that shouldn't be escaped
        $rawVars = ['options', 'select', 'rows', 'input', 'form_action', 'value', 'hook'];
        
        foreach ($rawVars as $rawVar) {
            if (strpos($var, $rawVar) !== false) {
                return '{{ ' . $var . '|raw }}';
            }
        }
        
        return $matches[0]; // Return unchanged if not in the raw list
    }, $content);
    
    // Save the converted template
    file_put_contents($newFileName, $content);
    echo "Created $newFileName\n";
}

echo "\nConversion complete. Converted " . count($files) . " templates to Twig syntax.\n";
echo "Please review the converted templates and make any necessary adjustments.\n";