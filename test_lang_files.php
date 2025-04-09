<?php
/**
 * Test script to verify the correct formatting of phpgw_*.lang files
 * and check for missing translations between language files
 *
 * Format should be: key<tab>module<tab>lang<tab>value
 *
 * Usage:
 *   php test_lang_files.php                    # Check all language files
 *   php test_lang_files.php --verbose          # Show detailed information about issues
 *   php test_lang_files.php --lang=no,en       # Check only Norwegian and English files
 *   php test_lang_files.php --output=json      # Output results in JSON format for parsing
 *   php test_lang_files.php --compare          # Compare languages for missing translations
 *   php test_lang_files.php --modules=booking,property           # Check specific modules only
 *   php test_lang_files.php --module=booking                  # Alternative syntax for modules
 *   php test_lang_files.php --compare --modules=booking,property  # Compare langs in specific modules
 *   php test_lang_files.php --compare --baseline=en  # Use English as baseline for comparison
 *   php test_lang_files.php --search=module.key --langs=no,en # Search for module.key in specified languages
 */

// Configuration
$base_dir = __DIR__; // Base directory to start the search
$errors = [];
$files_checked = 0;
$lines_checked = 0;
$error_categories = [
	'wrong_tab_count' => 0,
	'extra_tabs' => 0,
	'spaces_instead_of_tabs' => 0,
	'empty_fields' => 0,
	'other' => 0,
];

// Parse command line options
$help_requested = in_array('--help', $argv) || in_array('-h', $argv);
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);
$json_output = in_array('--output=json', $argv);
$compare_mode = in_array('--compare', $argv);
$sort_mode = in_array('--sort', $argv);
$search_mode = false;
$search_key = '';
$langs = [];
$modules = [];
$baseline_lang = 'en'; // Default baseline language for comparison

// Parse more arguments
foreach ($argv as $arg)
{
	if (strpos($arg, '--lang=') === 0)
	{
		$lang_list = substr($arg, 7); // Remove '--lang='
		$langs = array_map('trim', explode(',', $lang_list));
	}
	elseif (strpos($arg, '--langs=') === 0)
	{
		$lang_list = substr($arg, 8); // Remove '--langs='
		$langs = array_map('trim', explode(',', $lang_list));
	}
	elseif (strpos($arg, '--modules=') === 0)
	{
		$module_list = substr($arg, 10); // Remove '--modules='
		$modules = array_map('trim', explode(',', $module_list));
	}
	elseif (strpos($arg, '--module=') === 0)
	{
		$module_list = substr($arg, 9); // Remove '--module='
		$modules = array_map('trim', explode(',', $module_list));
	}
	elseif (strpos($arg, '--baseline=') === 0)
	{
		$baseline_lang = substr($arg, 11); // Remove '--baseline='
	}
	elseif (strpos($arg, '--search=') === 0)
	{
		$search_key = substr($arg, 9); // Remove '--search='
		$search_mode = true;
	}
}

// Display help if requested
if ($help_requested)
{
	echo "Language File Validator and Comparison Tool\n";
	echo "=========================================\n\n";
	echo "This tool validates the format of phpgw_*.lang files and can compare translations between languages.\n";
	echo "Each line should follow the format: key<tab>module<tab>lang<tab>value\n\n";
	echo "Usage:\n";
	echo "  php " . basename(__FILE__) . " [options]\n\n";
	echo "Options:\n";
	echo "  --help, -h           Display this help message\n";
	echo "  --verbose, -v        Show more detailed information about the issues\n";
	echo "  --output=json        Output results in JSON format for parsing\n";
	echo "  --lang=xx,yy,zz      Only check files for specific languages (comma-separated)\n";
	echo "                       Example: --lang=no,en,de\n";
	echo "  --langs=xx,yy,zz     Alternative syntax for --lang\n";
	echo "  --compare            Compare languages for missing translations\n";
	echo "  --modules=a,b,c      Only check specified modules (in both modes)\n";
	echo "  --module=a,b,c       Alternative syntax for --modules\n";
	echo "  --baseline=xx        Use specified language as baseline for comparison (default: en)\n";
	echo "  --sort               Sort language files alphabetically by key and deduplicate entries\n";
	echo "  --search=module.key  Search for a specific translation key in format module.key\n";
	echo "                       Example: --search=booking.save\n\n";
	echo "Examples:\n";
	echo "  php " . basename(__FILE__) . "                     # Check all language files\n";
	echo "  php " . basename(__FILE__) . " --verbose           # Show detailed diagnostic information\n";
	echo "  php " . basename(__FILE__) . " --lang=no,en        # Check only Norwegian and English files\n";
	echo "  php " . basename(__FILE__) . " --module=booking    # Check only files in booking module\n";
	echo "  php " . basename(__FILE__) . " --output=json       # Output in JSON format for processing\n";
	echo "  php " . basename(__FILE__) . " --compare --lang=no,en,nn  # Find missing translations between languages\n";
	echo "  php " . basename(__FILE__) . " --compare --modules=booking,property  # Compare only specific modules\n";
	echo "  php " . basename(__FILE__) . " --compare --module=booking      # Compare only a single module\n";
	echo "  php " . basename(__FILE__) . " --compare --baseline=no    # Use Norwegian as baseline for comparison\n";
	echo "  php " . basename(__FILE__) . " --sort --lang=en,no,nn --module=booking  # Sort and deduplicate booking module language files\n";
	echo "  php " . basename(__FILE__) . " --search=booking.save --langs=no,en,nn  # Search for 'booking.save' key in specified languages\n";
	exit(0);
}

// Show which languages are being checked
if (!empty($langs))
{
	echo "Filtering to only check languages: " . implode(', ', $langs) . "\n";
}

// If modules specified
if (!empty($modules))
{
	echo "Filtering to only check modules: " . implode(', ', $modules) . "\n";
}

if ($compare_mode)
{
	echo "Using '" . $baseline_lang . "' as baseline language for comparison\n";
}

// Function to check a language file
function check_lang_file($file_path)
{
	global $errors, $lines_checked, $error_categories;

	$content = file_get_contents($file_path);
	$lines = explode("\n", $content);
	$line_number = 0;

	foreach ($lines as $line)
	{
		$line_number++;
		$original_line = $line;
		$line = trim($line);

		// Skip empty lines and comments
		if (empty($line) || strpos($line, '#') === 0)
		{
			continue;
		}

		$lines_checked++;

		// Check if spaces are used instead of tabs
		if (strpos($line, ' ') !== false && strpos($line, "\t") === false)
		{
			// Spaces instead of tabs issue
			$error = [
				'file' => $file_path,
				'line' => $line_number,
				'content' => $line,
				'error' => 'Using spaces instead of tabs',
				'category' => 'spaces_instead_of_tabs',
				'severity' => 'error'
			];

			$errors[] = $error;
			$error_categories['spaces_instead_of_tabs']++;
			continue;
		}

		// Split by tab character
		$parts = explode("\t", $line);

		// Check if we have exactly 4 parts
		if (count($parts) !== 4)
		{
			$error_type = count($parts) < 4 ? 'wrong_tab_count' : 'extra_tabs';
			$error = [
				'file' => $file_path,
				'line' => $line_number,
				'content' => $line,
				'part_count' => count($parts),
				'error' => 'Expected 4 tab-separated parts (key, module, lang, value), found ' . count($parts),
				'category' => $error_type,
				'severity' => 'error'
			];

			$errors[] = $error;
			$error_categories[$error_type]++;
			continue;
		}

		// Check that each part is not empty
		$empty_fields = [];
		foreach ($parts as $index => $part)
		{
			if (trim($part) === '')
			{
				$field_names = ['key', 'module', 'lang', 'value'];
				$empty_fields[] = $field_names[$index];
			}
		}

		if (!empty($empty_fields))
		{
			$errors[] = [
				'file' => $file_path,
				'line' => $line_number,
				'content' => $line,
				'error' => "Empty fields: " . implode(', ', $empty_fields),
				'category' => 'empty_fields',
				'severity' => 'error',
				'empty_fields' => $empty_fields
			];
			$error_categories['empty_fields']++;
		}

		// Check for whitespace issues at the beginning/end of fields
		$has_whitespace_issues = false;
		$whitespace_issues = [];

		foreach ($parts as $index => $part)
		{
			$field_names = ['key', 'module', 'lang', 'value'];
			if ($part !== trim($part))
			{
				$has_whitespace_issues = true;
				$whitespace_issues[] = $field_names[$index];
			}
		}

		if ($has_whitespace_issues)
		{
			$errors[] = [
				'file' => $file_path,
				'line' => $line_number,
				'content' => $line,
				'error' => "Extra whitespace in fields: " . implode(', ', $whitespace_issues),
				'category' => 'other',
				'severity' => 'warning',
				'whitespace_issues' => $whitespace_issues
			];
			$error_categories['other']++;
		}
	}
}

// Find all phpgw_*.lang files recursively or directly for specific modules
function find_lang_files($dir)
{
	global $files_checked, $langs, $modules, $verbose;
	$results = [];

	// Special handling for module filtering
	if (!empty($modules)) {
		// Direct module path lookup
		foreach ($modules as $module) {
			$module_setup_dir = $dir . "/src/modules/" . $module . "/setup";
			if ($verbose) {
				echo "Checking module directory: {$module_setup_dir}\n";
			}
			
			if (is_dir($module_setup_dir)) {
				$module_files = scandir($module_setup_dir);
				foreach ($module_files as $file) {
					if (preg_match('/phpgw_(.*)\.lang$/', $file, $matches)) {
						$lang_code = $matches[1];
						$path = $module_setup_dir . "/" . $file;
						
						// Filter by language if specified
						if (!empty($langs) && !in_array($lang_code, $langs)) {
							if ($verbose) {
								echo "  Skipping non-matching language: {$lang_code}\n";
							}
							continue;
						}
						
						if ($verbose) {
							echo "  Found language file: {$path}\n";
						}
						
						$results[] = $path;
						$files_checked++;
					}
				}
			} else if ($verbose) {
				echo "  Module directory not found: {$module_setup_dir}\n";
			}
		}
		
		return $results;
	}
	
	// Regular recursive search for all modules
	if ($verbose) {
		echo "Scanning directory: {$dir}\n";
	}

	$files = scandir($dir);
	foreach ($files as $file)
	{
		if ($file === '.' || $file === '..') continue;

		$path = $dir . '/' . $file;

		if (is_dir($path))
		{
			// Skip the 'out' directory as it contains compiled output
			if (basename($path) === 'out') {
				if ($verbose) {
					echo "  Skipping 'out' directory: {$path}\n";
				}
				continue;
			}
			
			// For better performance, skip some common non-module directories
			if (in_array(basename($path), ['.git', 'vendor', 'node_modules'])) {
				if ($verbose) {
					echo "  Skipping directory: {$path}\n";
				}
				continue;
			}

			$results = array_merge($results, find_lang_files($path));
		} elseif (preg_match('/phpgw_(.*)\.lang$/', $file, $matches))
		{
			$lang_code = $matches[1];
			
			if ($verbose) {
				echo "  Found language file: {$path} (language: {$lang_code})\n";
			}

			// Filter by language if specified
			if (!empty($langs) && !in_array($lang_code, $langs))
			{
				if ($verbose) {
					echo "    Skipping non-matching language: {$lang_code}\n";
				}
				continue;
			}

			if ($verbose) {
				echo "  Adding to results: {$path}\n";
			}
			$results[] = $path;
			$files_checked++;
		}
	}

	return $results;
}

// Function to search for a specific key in module.key format
function search_for_key($lang_files, $search_key)
{
	global $verbose, $base_dir, $langs;
	
	// Split the search key into module and key parts
	if (strpos($search_key, '.') !== false) {
		list($search_module, $search_phrase) = explode('.', $search_key, 2);
	} else {
		// If no dot is present, treat the entire string as the key and search in all modules
		$search_module = null;
		$search_phrase = $search_key;
	}
	
	if ($verbose) {
		echo "Searching for ";
		if ($search_module) {
			echo "module: '{$search_module}', ";
		}
		echo "key: '{$search_phrase}'\n";
	}
	
	$results = [];
	$total_matches = 0;
	
	foreach ($lang_files as $file_path) {
		// Extract language from file path
		if (preg_match('/phpgw_(.*)\.lang$/', basename($file_path), $matches)) {
			$lang_code = $matches[1];
		} else {
			$lang_code = 'unknown';
		}
		
		// Extract module from the file path
		$module = get_module_from_path($file_path);
		
		// Skip if we're searching for a specific module and this isn't it
		if ($search_module !== null && $search_module !== $module) {
			if ($verbose) {
				echo "Skipping {$file_path}, module '{$module}' doesn't match search module '{$search_module}'\n";
			}
			continue;
		}
		
		$content = file_get_contents($file_path);
		$lines = explode("\n", $content);
		$matches = [];
		
		foreach ($lines as $line_number => $line) {
			$line = trim($line);
			
			// Skip empty lines and comments
			if (empty($line) || strpos($line, '#') === 0) {
				continue;
			}
			
			$parts = explode("\t", $line);
			if (count($parts) === 4) {
				$key = $parts[0];
				$module_in_file = $parts[1];
				$lang = $parts[2];
				$value = $parts[3];
				
				// Only match if the module matches our search (if specified)
				if ($search_module !== null && $search_module !== $module_in_file) {
					continue;
				}
				
				// Check if key matches search phrase
				if ($key === $search_phrase) {
					$matches[] = [
						'line_number' => $line_number + 1, // 1-based line numbers
						'key' => $key,
						'module' => $module_in_file,
						'lang' => $lang,
						'value' => $value,
						'full_line' => $line
					];
				}
			}
		}
		
		if (!empty($matches)) {
			$relative_path = str_replace($base_dir, '', $file_path);
			$results[$file_path] = [
				'file' => $relative_path,
				'language' => $lang_code,
				'module' => $module,
				'matches' => $matches,
				'match_count' => count($matches)
			];
			$total_matches += count($matches);
		}
	}
	
	return [
		'results' => $results,
		'total_matches' => $total_matches,
		'search_module' => $search_module,
		'search_key' => $search_phrase
	];
}

// Function to extract module name from file path
function get_module_from_path($file_path)
{
	global $verbose;
	if (preg_match('#/modules/([^/]+)/#', $file_path, $matches)) {
		$module = $matches[1];
		if ($verbose) {
			echo "Extracted module '{$module}' from path '{$file_path}'\n";
		}
		return $module;
	}
	if ($verbose) {
		echo "Could not extract module from path '{$file_path}'\n";
	}
	return 'unknown';
}

// Function to extract language entries from a file
function extract_lang_entries($file_path)
{
	$entries = [];
	$content = file_get_contents($file_path);
	$lines = explode("\n", $content);

	foreach ($lines as $line) {
		$line = trim($line);
		
		// Skip empty lines and comments
		if (empty($line) || strpos($line, '#') === 0) {
			continue;
		}
		
		$parts = explode("\t", $line);
		if (count($parts) === 4) {
			$key = $parts[0];
			$module = $parts[1];
			$entries["$key|$module"] = [
				'key' => $key,
				'module' => $module,
				'lang' => $parts[2],
				'value' => $parts[3],
				'original_line' => $line
			];
		}
	}
	
	return $entries;
}

// Function to sort and save a language file
function sort_lang_file($file_path)
{
	global $verbose;
	
	// Read the file content
	$content = file_get_contents($file_path);
	if ($content === false) {
		echo "Error: Could not read file {$file_path}\n";
		return false;
	}
	
	// Split into lines
	$lines = explode("\n", $content);
	$entries = [];
	$comments = [];
	$empty_lines = [];
	$duplicates = [];
	
	// Extract all entries, preserving comments and empty lines
	foreach ($lines as $line_number => $line) {
		$trimmed = trim($line);
		
		if (empty($trimmed)) {
			$empty_lines[$line_number] = $line;
		} elseif (strpos($trimmed, '#') === 0) {
			$comments[$line_number] = $line;
		} else {
			$parts = explode("\t", $line);
			if (count($parts) === 4) {
				$key = $parts[0];
				$module = $parts[1];
				$lang = $parts[2];
				$value = $parts[3];
				
				// Create a unique identifier for detecting duplicates
				$entry_id = $key . '|' . $module;
				
				if (isset($entries[$entry_id])) {
					// We found a duplicate!
					if (!isset($duplicates[$entry_id])) {
						$duplicates[$entry_id] = 1;
					}
					$duplicates[$entry_id]++;
					
					if ($verbose) {
						echo "Found duplicate entry in {$file_path} at line {$line_number}: {$key} (module: {$module})\n";
					}
				}
				
				// Always use the last occurrence of a duplicate key
				$entries[$entry_id] = [
					'key' => $key,
					'module' => $module,
					'lang' => $lang,
					'value' => $value,
					'original_line' => $line
				];
			} else {
				// Skip malformed lines
				if ($verbose) {
					echo "Warning: Skipping malformed line {$line_number} in {$file_path}\n";
				}
			}
		}
	}
	
	// Report on duplicates found
	$duplicate_count = count($duplicates);
	if ($duplicate_count > 0) {
		echo "Found {$duplicate_count} duplicate entries in {$file_path} (keeping only the last occurrence)\n";
		if ($verbose) {
			foreach ($duplicates as $entry_id => $count) {
				list($key, $module) = explode('|', $entry_id, 2);
				echo "  - '{$key}' (module: {$module}) appeared {$count} times\n";
			}
		}
	}
	
	// Convert to indexed array for sorting
	$entries_array = array_values($entries);
	
	// Sort entries by key
	usort($entries_array, function ($a, $b) {
		return strcasecmp($a['key'], $b['key']);
	});
	
	// Rebuild the file content
	$sorted_lines = [];
	foreach ($entries_array as $entry) {
		$sorted_lines[] = $entry['key'] . "\t" . $entry['module'] . "\t" . $entry['lang'] . "\t" . $entry['value'];
	}
	
	// Write back to file
	$new_content = implode("\n", $sorted_lines);
	if (file_put_contents($file_path, $new_content) === false) {
		echo "Error: Could not write to file {$file_path}\n";
		return false;
	}
	
	$result_message = "Successfully sorted {$file_path} by key";
	if ($duplicate_count > 0) {
		$result_message .= " (removed {$duplicate_count} duplicates)";
	}
	
	if ($verbose || $duplicate_count > 0) {
		echo "{$result_message}\n";
	}
	
	return true;
}

// Function to compare language files for missing translations
function compare_lang_files($lang_files)
{
	global $modules, $baseline_lang, $verbose;
	
	// Group files by module and language
	$files_by_module_and_lang = [];
	foreach ($lang_files as $file_path) {
		$module = get_module_from_path($file_path);
		
		// Skip if not in specified modules (when modules filter is active)
		if (!empty($modules) && !in_array($module, $modules)) {
			continue;
		}
		
		if (preg_match('/phpgw_(.*)\.lang$/', basename($file_path), $matches)) {
			$lang = $matches[1];
			$files_by_module_and_lang[$module][$lang] = $file_path;
		}
	}
	
	// Check for missing translations
	$missing_translations = [];
	$total_missing = 0;
	
	foreach ($files_by_module_and_lang as $module => $lang_files) {
		// Skip if baseline language is missing for this module
		if (!isset($lang_files[$baseline_lang])) {
			echo "Warning: Baseline language '$baseline_lang' not found for module '$module', skipping comparison\n";
			continue;
		}
		
		// Extract entries from baseline language
		$baseline_entries = extract_lang_entries($lang_files[$baseline_lang]);
		$baseline_count = count($baseline_entries);
		
		foreach ($lang_files as $lang => $file_path) {
			if ($lang === $baseline_lang) continue; // Skip baseline language
			
			$target_entries = extract_lang_entries($file_path);
			$target_count = count($target_entries);
			
			// Find entries in baseline that are missing in target language
			$missing = [];
			foreach ($baseline_entries as $key => $entry) {
				if (!isset($target_entries[$key])) {
					$missing[] = [
						'key' => $entry['key'],
						'module' => $entry['module'],
						'baseline_value' => $entry['value']
					];
				}
			}
			
			// Find entries in target that are not in baseline (extra translations)
			$extra = [];
			foreach ($target_entries as $key => $entry) {
				if (!isset($baseline_entries[$key])) {
					$extra[] = [
						'key' => $entry['key'],
						'module' => $entry['module'],
						'target_value' => $entry['value']
					];
				}
			}
			
			$missing_count = count($missing);
			$extra_count = count($extra);
			$total_missing += $missing_count;
			
			if ($missing_count > 0 || $extra_count > 0 || $verbose) {
				$missing_translations[$module][$lang] = [
					'file' => $file_path,
					'baseline_count' => $baseline_count,
					'target_count' => $target_count,
					'missing' => $missing,
					'missing_count' => $missing_count,
					'extra' => $extra,
					'extra_count' => $extra_count
				];
			}
		}
	}
	
	return [
		'missing_translations' => $missing_translations,
		'total_missing' => $total_missing
	];
}

// Find and check all lang files
echo "Starting validation of language files...\n";
$lang_files = find_lang_files($base_dir);

if (empty($lang_files))
{
	echo "No language files found! Please check the base directory.\n";
	exit(1);
}

// SEARCH MODE
if ($search_mode) {
	if (empty($search_key)) {
		echo "Error: You must provide a search key with --search=module.key\n";
		exit(1);
	}
	
	echo "Mode: Searching for key '{$search_key}'\n";
	
	// If no languages specified, search in all languages
	if (empty($langs)) {
		echo "No languages specified, will search in all available language files.\n";
	} else {
		echo "Searching in languages: " . implode(', ', $langs) . "\n";
	}
	
	$search_results = search_for_key($lang_files, $search_key);
	$total_matches = $search_results['total_matches'];
	$search_module = $search_results['search_module'];
	$search_phrase = $search_results['search_key'];
	$results = $search_results['results'];
	
	// Output search results
	echo "\nSearch Results:\n";
	echo "==============\n\n";
	
	if ($search_module) {
		echo "Searching for key '{$search_phrase}' in module '{$search_module}'\n\n";
	} else {
		echo "Searching for key '{$search_phrase}' in all modules\n\n";
	}
	
	if ($total_matches === 0) {
		echo "No matches found for the key '{$search_key}'.\n";
		
		// Provide suggestions or help
		echo "\nSuggestions:\n";
		echo "  - Check if the module name is correct\n";
		echo "  - Try searching without module prefix using: --search={$search_phrase}\n";
		echo "  - Ensure you're searching in the correct languages with --langs=xx,yy\n";
		exit(0);
	}
	
	echo "Found {$total_matches} matches in " . count($results) . " files.\n\n";
	
	// Group results by language for easier reading
	$results_by_lang = [];
	foreach ($results as $file_path => $result) {
		$lang = $result['language'];
		if (!isset($results_by_lang[$lang])) {
			$results_by_lang[$lang] = [];
		}
		$results_by_lang[$lang][$file_path] = $result;
	}
	
	// Sort languages alphabetically
	ksort($results_by_lang);
	
	// Display results grouped by language
	foreach ($results_by_lang as $lang => $lang_results) {
		$lang_match_count = 0;
		foreach ($lang_results as $result) {
			$lang_match_count += $result['match_count'];
		}
		
		echo "LANGUAGE: {$lang} ({$lang_match_count} matches)\n";
		echo "=======================\n\n";
		
		foreach ($lang_results as $file_path => $result) {
			echo "File: {$result['file']} (Module: {$result['module']})\n";
			
			foreach ($result['matches'] as $match) {
				echo "  Line {$match['line_number']}: {$match['key']}\t{$match['module']}\t{$match['lang']}\t{$match['value']}\n";
			}
			echo "\n";
		}
	}
	
	// If JSON output is requested
	if ($json_output) {
		$output = [
			'search_query' => [
				'module' => $search_module,
				'key' => $search_phrase,
				'full_query' => $search_key
			],
			'summary' => [
				'total_matches' => $total_matches,
				'files_with_matches' => count($results),
				'languages_with_matches' => count($results_by_lang)
			],
			'results' => $results,
			'results_by_language' => $results_by_lang
		];
		
		echo json_encode($output, JSON_PRETTY_PRINT);
	}
	
	exit(0);
}

// SORT MODE
if ($sort_mode) {
	echo "Mode: Sorting language files by key and deduplicating entries\n";
	
	$sorted_count = 0;
	$failed_count = 0;
	
	foreach ($lang_files as $file_path) {
		$relative_path = str_replace($base_dir, '', $file_path);
		echo "Sorting file: {$relative_path}...\n";
		
		if (sort_lang_file($file_path)) {
			$sorted_count++;
		} else {
			$failed_count++;
		}
	}
	
	echo "\nCompleted sorting language files:\n";
	echo "  - Successfully sorted: {$sorted_count}\n";
	
	if ($failed_count > 0) {
		echo "  - Failed to sort: {$failed_count}\n";
		exit(1);
	}
	
	echo "\nAll language files have been sorted alphabetically by key and deduplicated.\n";
	exit(0);
}

// COMPARISON MODE
if ($compare_mode) {
	// Ensure we have at least two languages to compare
	if (count($langs) < 2) {
		echo "You need to specify at least two languages to compare with --lang=lang1,lang2,...\n";
		exit(1);
	}
	
	// First check if all language files are valid
	foreach ($lang_files as $file)
	{
		check_lang_file($file);
	}
	
	// If there are format errors, report them first
	if (!empty($errors)) {
		echo "Found formatting errors in language files. Please fix these before comparing translations.\n";
		format_error_report($errors);
		exit(1);
	}
	
	echo "All language files are correctly formatted. Proceeding with translation comparison...\n\n";
	
	// Compare languages for missing translations
	$comparison_results = compare_lang_files($lang_files);
	$missing_translations = $comparison_results['missing_translations'];
	$total_missing = $comparison_results['total_missing'];
	
	// Process comparison results
	if (empty($missing_translations) && !$verbose) {
		echo "SUCCESS: No missing translations found between languages.\n";
		exit(0);
	}
	
	// Generate report
	echo "COMPARISON REPORT: Found {$total_missing} missing translations.\n\n";
	
	// Calculate missing translations by language
	$missing_by_lang = [];
	foreach ($missing_translations as $module => $langs) {
		foreach ($langs as $lang => $data) {
			if (!isset($missing_by_lang[$lang])) {
				$missing_by_lang[$lang] = 0;
			}
			$missing_by_lang[$lang] += $data['missing_count'];
		}
	}
	
	// Show missing translations by language
	if (!empty($missing_by_lang)) {
		echo "Missing translations by language:\n";
		arsort($missing_by_lang); // Sort by count (descending)
		foreach ($missing_by_lang as $lang => $count) {
			echo "  - {$lang}: {$count} translations missing\n";
		}
		echo "\n";
	}
	
	// Show missing translations by module
	$missing_by_module = [];
	foreach ($missing_translations as $module => $langs) {
		$module_missing = 0;
		foreach ($langs as $lang => $data) {
			$module_missing += $data['missing_count'];
		}
		$missing_by_module[$module] = $module_missing;
	}
	
	if (!empty($missing_by_module)) {
		echo "Missing translations by module:\n";
		arsort($missing_by_module); // Sort by count (descending)
		foreach ($missing_by_module as $module => $count) {
			echo "  - {$module}: {$count} translations missing\n";
		}
		echo "\n";
	}
	
	// Detailed report in verbose mode
	if ($verbose) {
		foreach ($missing_translations as $module => $langs) {
			echo "MODULE: {$module}\n";
			echo "===================\n\n";
			
			foreach ($langs as $lang => $data) {
				$file = $data['file'];
				$baseline_count = $data['baseline_count'];
				$target_count = $data['target_count'];
				$missing_count = $data['missing_count'];
				$extra_count = $data['extra_count'];
				
				$relative_path = str_replace($base_dir, '', $file);
				echo "Language: {$lang} ({$relative_path})\n";
				echo "  - Baseline entries ({$baseline_lang}): {$baseline_count}\n";
				echo "  - Target entries ({$lang}): {$target_count}\n";
				echo "  - Missing from {$lang}: {$missing_count}\n";
				echo "  - Extra in {$lang}: {$extra_count}\n\n";
				
				if ($missing_count > 0) {
					echo "  MISSING TRANSLATIONS IN {$lang}:\n";
					echo "  ---------------------------\n";
					foreach ($data['missing'] as $entry) {
						echo "  * Key: {$entry['key']}, Module: {$entry['module']}\n";
						echo "    {$baseline_lang} Value: \"{$entry['baseline_value']}\"\n";
						echo "\n";
					}
					echo "\n";
				}
				
				if ($extra_count > 0 && $verbose) {
					echo "  EXTRA TRANSLATIONS IN {$lang}:\n";
					echo "  -------------------------\n";
					foreach ($data['extra'] as $entry) {
						echo "  * Key: {$entry['key']}, Module: {$entry['module']}\n";
						echo "    {$lang} Value: \"{$entry['target_value']}\"\n";
						echo "\n";
					}
					echo "\n";
				}
			}
			echo "\n";
		}
	} else {
		// Show summary information in non-verbose mode
		echo "Run with --verbose to see detailed information about missing translations.\n";
	}
	
	// Generate sample code for adding missing translations
	if ($total_missing > 0) {
		echo "To fix missing translations, add entries in the appropriate language files using this format:\n";
		echo "key<tab>module<tab>lang<tab>value\n\n";
		
		// Example of a random missing translation
		$example_module = key($missing_translations);
		$example_lang = key($missing_translations[$example_module]);
		$example_entry = $missing_translations[$example_module][$example_lang]['missing'][0] ?? null;
		
		if ($example_entry) {
			echo "Example for adding a missing translation:\n";
			echo "{$example_entry['key']}\t{$example_entry['module']}\t{$example_lang}\tTranslation text here\n";
		}
	}
	
	exit(0);
}

// STANDARD VALIDATION MODE
foreach ($lang_files as $file)
{
	check_lang_file($file);
}

format_error_report($errors);

// Function to format and display error report
function format_error_report($errors) {
	global $files_checked, $lines_checked, $error_categories, $verbose, $json_output, $base_dir;
	
	// Group errors by file for better reporting
	$errors_by_file = [];
	$errors_by_category = [];

	foreach ($errors as $error)
	{
		$file_path = $error['file'];
		$category = isset($error['category']) ? $error['category'] : 'other';

		if (!isset($errors_by_file[$file_path]))
		{
			$errors_by_file[$file_path] = [];
		}
		$errors_by_file[$file_path][] = $error;

		if (!isset($errors_by_category[$category]))
		{
			$errors_by_category[$category] = [];
		}
		$errors_by_category[$category][] = $error;
	}

	// Sort files by number of errors (descending)
	uasort($errors_by_file, function ($a, $b)
	{
		return count($b) - count($a);
	});

	// Output results as JSON if requested
	if ($json_output)
	{
		$output = [
			'summary' => [
				'files_checked' => $files_checked,
				'lines_checked' => $lines_checked,
				'files_with_errors' => count($errors_by_file),
				'total_errors' => count($errors),
				'error_categories' => $error_categories
			],
			'errors' => $errors,
			'errors_by_file' => $errors_by_file
		];

		echo json_encode($output, JSON_PRETTY_PRINT);
		exit(count($errors) > 0 ? 1 : 0);
	}

	// Standard output
	echo "Completed checking {$files_checked} files and {$lines_checked} language entries.\n\n";

	if (empty($errors))
	{
		echo "SUCCESS: All language files are correctly formatted.\n";
		exit(0);
	} else
	{
		$total_errors = count($errors);
		$files_with_errors = count($errors_by_file);

		echo "VALIDATION REPORT: Found {$total_errors} formatting issues in {$files_with_errors} files.\n\n";

		// Output error summary by category
		echo "Issues by category:\n";
		foreach ($error_categories as $category => $count)
		{
			if ($count > 0)
			{
				$category_name = str_replace('_', ' ', $category);
				echo "  - " . ucfirst($category_name) . ": {$count}\n";
			}
		}
		echo "\n";

		// Files with the most errors (top 5)
		$top_files = array_slice($errors_by_file, 0, 5, true);
		echo "Top " . count($top_files) . " files with the most issues:\n";
		foreach ($top_files as $file_path => $file_errors)
		{
			$relative_path = str_replace($base_dir, '', $file_path);
			echo "  - {$relative_path}: " . count($file_errors) . " issues\n";
		}
		echo "\n";

		// Detailed output if verbose mode is enabled
		if ($verbose)
		{
			foreach ($errors_by_file as $file_path => $file_errors)
			{
				$relative_path = str_replace($base_dir, '', $file_path);
				echo "File: {$file_path}\n";
				echo "Found " . count($file_errors) . " issues:\n";

				foreach ($file_errors as $index => $error)
				{
					$severity = isset($error['severity']) ? strtoupper($error['severity']) : 'ERROR';

					// IntelliJ-friendly format: file:line:column: message
					// This allows clicking on errors in the IDE output
					echo "{$file_path}:{$error['line']}:1: [{$severity}] {$error['error']}\n";

					// More detailed info
					if (isset($error['content']))
					{
						echo "  Content: {$error['content']}\n";
					}

					// Show fields if it's helpful
					if (isset($error['part_count']) && $error['part_count'] > 0)
					{
						$parts = explode("\t", $error['content']);
						echo "  Fields found (" . count($parts) . "):\n";
						foreach ($parts as $i => $part)
						{
							$field_name = $i < 4 ? ['key', 'module', 'lang', 'value'][$i] : "extra-{$i}";
							echo "    {$field_name}: \"{$part}\"\n";
						}
					}

					if (isset($error['empty_fields']) && !empty($error['empty_fields']))
					{
						echo "  Empty fields: " . implode(', ', $error['empty_fields']) . "\n";
					}

					if (isset($error['whitespace_issues']) && !empty($error['whitespace_issues']))
					{
						echo "  Fields with extra whitespace: " . implode(', ', $error['whitespace_issues']) . "\n";
					}

					echo "\n";
				}
				echo "\n";
			}
		} else
		{
			// Non-verbose mode just shows clickable error locations
			foreach ($errors_by_file as $file_path => $file_errors)
			{
				foreach ($file_errors as $error)
				{
					$severity = isset($error['severity']) ? strtoupper($error['severity']) : 'ERROR';
					echo "{$file_path}:{$error['line']}:1: [{$severity}] {$error['error']}\n";
				}
			}
			echo "\nRun with --verbose for detailed information about each issue.\n";
		}

		echo "\nSummary: {$total_errors} errors in {$files_with_errors} files out of {$files_checked} files checked.\n";
		echo "Fix each issue by ensuring every line follows the format: key<tab>module<tab>lang<tab>value\n";
		exit(1);
	}
}