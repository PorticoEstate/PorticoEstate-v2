<?php
/**
 * Template Converter - Legacy Template to Twig
 * 
 * This script converts legacy template files (.tpl) to Twig templates (.html.twig)
 */

// Configuration
$sourceDir = 'src/modules/admin/templates/base/';
$targetDir = 'src/modules/admin/templates/twig/';

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
    
    // 1. Convert variable syntax from {var} to {{ var }}
    $content = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function($matches) {
        return '{{ ' . $matches[1] . ' }}';
    }, $content);
    
    // 2. Convert blocks from <!-- BEGIN blockname -->...<!-- END blockname --> to {% block blockname %}...{% endblock %}
    $content = preg_replace_callback('/<!-- BEGIN ([a-zA-Z0-9_]+) -->(.+?)<!-- END \\1 -->/s', function($matches) {
        return '{% block ' . $matches[1] . ' %}' . $matches[2] . '{% endblock %}';
    }, $content);
    
    // 3. Add Twig comment at the top
    $content = "{# " . ucfirst($baseName) . " template #}\n" . $content;
    
    // 4. Convert if statements
    $content = preg_replace('/<!-- IF ([^>]+) -->/', '{% if $1 %}', $content);
    $content = preg_replace('/<!-- ENDIF -->/', '{% endif %}', $content);
    
    // 5. Convert loops
    $content = preg_replace('/<!-- LOOP ([^>]+) -->/', '{% for item in $1 %}', $content);
    $content = preg_replace('/<!-- ENDLOOP -->/', '{% endfor %}', $content);
    
    // 6. Add comments for sections
    $content = preg_replace('/<tr class="th">\s*<td[^>]*>[^<]*<b>([^<]+)<\/b><\/td>\s*<\/tr>/', '<tr class="th">\n        <td colspan="2">&nbsp;<b>{{ $1 }}</b></td>\n    </tr>\n\n    {# $1 section #}', $content);
    
    // Save the converted template
    file_put_contents($newFileName, $content);
    echo "Created $newFileName\n";
}

echo "\nConversion complete. Converted " . count($files) . " templates to Twig syntax.\n";
echo "Please review the converted templates and make any necessary adjustments.\n";