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

### Adding New Translations

Add new translations to language files:

```bash
php test_lang_files.php --add-translation --key=key_name --module=module_name --langs=no:Norwegian text,en:English text
```

For "common" translations that should be available globally, use `common` as the module name:

```bash
php test_lang_files.php --add-translation --key=save --module=common --langs=no:Lagre,en:Save,nn:Lagra
```

**Important notes about translations with commas or special characters:**
- The script correctly handles translations containing commas, spaces, and special characters
- Use quotes around the entire --langs parameter to ensure proper parsing
- Example with commas and special characters:

```bash
php test_lang_files.php --add-translation --key=confirm --module=booking --langs="no:Er du sikker på at du vil fortsette?,en:Are you sure you want to continue?"
```

The script will automatically add the translations to the appropriate language files, creating them if they don't exist.

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

After identifying missing translations, you can add them to the language files:

1. **Method 1: Using the add-translation command (recommended):**
   ```
   php test_lang_files.php --add-translation --key=missing_key --module=module_name --langs=no:Norwegian text,en:English text,nn:Nynorsk text
   ```
   This automatically adds the translations to the correct language files.

2. **Method 2: Manual editing:**
   - View the file: `View /path/to/file.lang`
   - Edit the file to add the missing translations: `Edit /path/to/file.lang`

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

### Adding Missing Translations Automatically

To add translations for a missing key in multiple languages at once:

```
php test_lang_files.php --add-translation --key=save --module=booking --langs=no:Lagre,en:Save,nn:Lagra
```

For common translations (in phpgwapi):

```
php test_lang_files.php --add-translation --key=yes --module=common --langs=no:Ja,en:Yes,nn:Ja
```

With complex text containing commas, spaces, or special characters:

```
php test_lang_files.php --add-translation --key=error_message --module=booking --langs="no:Noe gikk galt, vennligst prøv igjen senere.,en:Something went wrong, please try again later."
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

#### Method 1: Using the command-line tool (recommended)

Add translations for a missing key using the command-line tool:

```
php test_lang_files.php --add-translation --key=my_missing_key --module=booking --langs=no:Min oversettelse,en:My translation
```

For translations with commas, spaces, or special characters, use quotes around the --langs parameter:

```
php test_lang_files.php --add-translation --key=confirm_delete --module=booking --langs="no:Er du sikker på at du vil slette denne?,en:Are you sure you want to delete this?"
```

This automatically adds the translations to the correct language files with proper formatting.

#### Method 2: Manual format

When manually adding a key to a language file, use this format:

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