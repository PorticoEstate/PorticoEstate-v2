# NOTE FOR DEV
example prompt:
```aiignore
I want to fix language files according to CLAUDE-LANG-GUIDE.md, lets focus on nn,en,no in the bookingfrontend module
```

# Guide for Working with Claude on Language Files

This guide helps you get started with Claude Code for fixing language files in the Aktivkommune project.

## Quick Start

Use the following command to check for missing translations:

```bash
php test_lang_files.php --compare --lang=en,no,nn --module=booking
```

## Available Commands

### Basic Format Checking

Check all language files for formatting issues:

```bash
php test_lang_files.php
```

Check format in a specific module:

```bash
php test_lang_files.php --module=booking
```

Check multiple specific modules:

```bash
php test_lang_files.php --modules=booking,bookingfrontend
```

### Compare Translations Between Languages

Check for missing translations across all languages:

```bash
php test_lang_files.php --compare --lang=en,no,nn
```

Check for missing translations in specific modules:

```bash
php test_lang_files.php --compare --lang=en,no,nn --modules=booking,bookingfrontend
```

Alternative syntax for single module:

```bash
php test_lang_files.php --compare --lang=en,no,nn --module=booking
```

Use a different baseline language (default is 'en'):

```bash
php test_lang_files.php --compare --lang=en,no,nn --module=booking --baseline=no
```

Get detailed information about missing translations:

```bash
php test_lang_files.php --compare --lang=en,no,nn --module=booking --verbose
```

### Sort Language Files

Sort language files alphabetically by key to improve maintainability:

```bash
php test_lang_files.php --sort --lang=en,no,nn --module=booking
```

Sort all language files in all modules:

```bash
php test_lang_files.php --sort
```

Sort only specific languages in a module:

```bash
php test_lang_files.php --sort --lang=en,no --module=bookingfrontend
```

### Search for Translation Keys

Search for a specific key in the format "module.key" across all language files:

```bash
php test_lang_files.php --search=booking.save
```

Search for a key in specific languages:

```bash
php test_lang_files.php --search=booking.save --langs=no,en,nn
```

Search for a key in all languages of a specific module:

```bash
php test_lang_files.php --search=save --module=booking
```

## Common Workflows with Claude

### 1. Initial Check

When starting a session with Claude, begin by running a check for a specific module:

```
php test_lang_files.php --compare --lang=en,no,nn --module=bookingfrontend
```

### 2. Finding Missing Translations

For detailed information about what's missing:

```
php test_lang_files.php --compare --lang=en,no,nn --module=booking --verbose
```

### 3. Checking Multiple Languages as Baseline

It's important to check with each language as the baseline to catch all issues:

```
php test_lang_files.php --compare --lang=en,no,nn --module=booking --baseline=en
php test_lang_files.php --compare --lang=en,no,nn --module=booking --baseline=no
php test_lang_files.php --compare --lang=en,no,nn --module=booking --baseline=nn
```

### 4. Checking Format Issues First

Before comparing translations, check for format issues in the language files:

```
php test_lang_files.php --module=booking --verbose
```

### 5. Searching for Specific Translation Keys

When you need to find where a specific translation key is used across languages:

```
php test_lang_files.php --search=booking.save --langs=en,no,nn
```

This helps when:
- You need to check how a key is translated in different languages
- You want to verify if a key exists in all required language files
- You need to find the context of a specific key for better translation

### 6. Sorting Language Files by Key

To make language files easier to maintain, you can sort them alphabetically by key:

```
php test_lang_files.php --sort --lang=en,no,nn --module=bookingfrontend
```

This organizes all translations in a consistent order across language files, making differences easier to spot and maintenance simpler.

### 7. Fixing Translations

After identifying missing translations, use Claude to add them to the language files:

1. View the file: `View /path/to/file.lang`
2. Edit the file to add the missing translations: `Edit /path/to/file.lang`
3. Verify changes fixed the issues:
   ```
   php test_lang_files.php --compare --lang=en,no,nn --module=module_name
   ```

## Common Issues & Solutions

### Case Sensitivity Issues

Watch out for case sensitivity in keys ("Complete applications" vs "complete applications").
Make sure all variants exist in all language files.

### Steps to Fix Missing Translations

1. First, examine the target language file structure
2. Check for similar translations in other languages
3. Add the missing translations at an appropriate location in the file
4. Verify the fix by running the comparison tool again

## Examples for Claude

### Checking All Modules

```
php test_lang_files.php --compare --lang=en,no,nn
```

### Finding Format Issues in All Modules

```
php test_lang_files.php
```

### Finding a Specific Module with Issues

```
for module in $(find src/modules -type d -mindepth 1 -maxdepth 1 -exec basename {} \;); do
  echo "Checking $module";
  php test_lang_files.php --compare --lang=en,no,nn --module=$module;
done
```

### Searching for a Specific Translation Key

Search for a specific key across all language files:

```
php test_lang_files.php --search=booking.save
```

Search for the same key in specific languages:

```
php test_lang_files.php --search=booking.save --langs=en,no,nn
```

This helps when you need to check if a translation exists in all required languages or when you need to see how a specific term is translated in different languages.

### Sorting Language Files for Better Maintainability

To sort all language files in a module:

```
php test_lang_files.php --sort --lang=en,no,nn --module=bookingfrontend
```

This makes files more readable and easier to compare across languages by ensuring consistent key ordering.

### Adding a Missing Translation

When a key is missing from a language file, add it like this:

```
key_name	module_name	language_code	translation
```

```
Format should be: key<tab>module<tab>lang<tab>value
```
For example:
```
my_missing_key	booking	no	Min oversettelse
```

### Using Both --module and --verbose

For detailed debugging information about a specific module:

```
php test_lang_files.php --module=booking --verbose
```