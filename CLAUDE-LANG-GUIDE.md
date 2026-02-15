# NOTE FOR DEV
example prompt:
```aiignore
I want to work with language files according to CLAUDE-LANG-GUIDE.md use the language tool as described there, lets focus on nn,en,no in the bookingfrontend module
```

# Guide for Working with Claude on Language Files

This guide helps you get started with Claude Code for fixing language files in the Aktivkommune project.

## Quick Start

Use the following command to check for missing translations:

```bash
php test_lang_files.php --compare --lang=en,no,nn --module=booking
```

## Installing Language Changes

After making changes to language files (adding new translations, fixing missing translations, etc.), you need to install the language changes to make them available in the application.

### Recommended Method: Using the Update Script

Use the automated script which handles authentication and installation:

```bash
bash test_scripts/update_language_files.sh
```

The script will:
- Automatically read the password from `config/header.inc.php`
- Log in to the setup interface
- Install the language files for en, no, and nn
- Verify the installation was successful

**Optional parameters:**
```bash
# Specify a custom password
bash test_scripts/update_language_files.sh your_password

# Specify custom languages (default: en,no,nn)
bash test_scripts/update_language_files.sh your_password "en,no,nn,sv"

# Use environment variables for custom base URL
BASE_URL=https://pe-api.test bash test_scripts/update_language_files.sh
```

### Alternative Method: Manual curl Command

For reference, you can also install language changes manually using curl:

```bash
curl 'http://pe-api.test/setup/lang' \
  -X POST \
  -H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:139.0) Gecko/20100101 Firefox/139.0' \
  -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
  -H 'Accept-Language: en-US,en;q=0.5' \
  -H 'Accept-Encoding: gzip, deflate' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -H 'Origin: http://pe-api.test' \
  -H 'Connection: keep-alive' \
  -H 'Referer: http://pe-api.test/setup/lang' \
  -H 'Cookie: last_loginid=henning; last_domain=default; template_set=bookingfrontend_2; domain=default; login_as_organization=1; after=%22%5C%2Fclient%5C%2Fno%3Fclick_history%3Db4577fa3de097daf0484f39b58d00879%22; ConfigPW=%242y%2412%24grdOg2MZij1YI6ErAqMDbu7lmDZeiG1jDgZ1l8ciTZ61Lue4HDTPi; ConfigDomain=default; ConfigLang=en; login_second_pass=1; selected_lang=no; bookingfrontendsession=833f7955e3061961ccd53d5985b67afc' \
  -H 'Upgrade-Insecure-Requests: 1' \
  -H 'Priority: u=0, i' \
  --data-raw 'lang_selected%5B%5D=en&lang_selected%5B%5D=no&lang_selected%5B%5D=nn&upgrademethod=dumpold&submit=Install'
```

**Note:** The update script is recommended as it handles authentication automatically and is more maintainable.

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

### Search for Translation Values

Search for translation values containing specific text across all language files:

```bash
php test_lang_files.php --search-value="booking has been registered"
```

Search for translation values in specific languages:

```bash
php test_lang_files.php --search-value="booking has been registered" --langs=no,en,nn
```

This feature is useful when:
- You know the text you're looking for but not the key name
- You want to find all translations containing specific words or phrases
- You need to check how a concept is translated across different languages
- You want to verify consistency in terminology across the application

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

### 6. Searching for Translation Values

When you need to find translations by their content rather than key:

```
php test_lang_files.php --search-value="booking has been registered" --langs=en,no,nn
```

This helps when:
- You know the text but not the translation key
- You want to find existing translations to reuse
- You need to check consistency in terminology
- You want to find all translations containing specific words

### 7. Sorting Language Files by Key

To make language files easier to maintain, you can sort them alphabetically by key:

```
php test_lang_files.php --sort --lang=en,no,nn --module=bookingfrontend
```

This organizes all translations in a consistent order across language files, making differences easier to spot and maintenance simpler.

### 8. Fixing Translations

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

## Dot-Notation Namespacing

The `lang()` function supports dot-notation to force translation lookup in a specific module, overriding the current module context.

### Syntax

```php
lang('my_key')              // Default: looks up in current module, falls back to 'common'
lang('booking.my_key')      // Forces lookup in the 'booking' module
lang('common.yes')          // Restricts lookup to 'common' translations only
lang('bookingfrontend.save')// Forces lookup in the 'bookingfrontend' module
```

### In Twig Templates

```twig
{{ lang('booking.my_key') }}
{{ lang('common.yes') }}
```

### When to Use

- **Cross-module lookups:** When you need a translation from a different module than the current context (e.g., a shared controller rendering translations from `booking` while in `bookingfrontend`).
- **Explicit common:** Use `lang('common.key')` when you specifically want a common translation and want to avoid accidental matches in the current module.
- **Default behavior is usually fine:** Within a module's own templates/controllers, plain `lang('key')` already looks up in the current module with common fallback.

### Safety

The dot prefix is only parsed as a namespace if the part before the dot contains **no spaces**. This means existing keys like `"loading..."`, `"no data. try again"`, etc. are unaffected — their prefix (`"loading"`, `"no data"`) either doesn't match a module or contains a space.

## Best Practices for Translation Keys

### Use Semantic Keys Instead of Full Text

**Bad:**
```
Your application has now been registered and a confirmation email has been sent to you.	bookingfrontend	en	Your application has now been registered and a confirmation email has been sent to you.
```

**Good:**
```
application_registered_confirmation	bookingfrontend	en	Your application has now been registered and a confirmation email has been sent to you.
```

### Key Naming Conventions

- Use snake_case for translation keys
- Make keys descriptive but concise
- Use module-specific prefixes when needed (e.g., `vipps_payment_completed`)
- Avoid full sentences as keys
- Use consistent terminology across related keys

### Examples of Good Translation Keys

```bash
# Add semantic keys for common messages
php test_lang_files.php --add-translation --key="application_registered_confirmation" --module=bookingfrontend --langs="en:Your application has now been registered and a confirmation email has been sent to you.,no:Søknaden har blitt sendt inn, og en bekreftelse har blitt sendt til deg på e-post."

# Add feature-specific keys
php test_lang_files.php --add-translation --key="vipps_payment_completed" --module=bookingfrontend --langs="en:Your booking has been completed via Vipps payment,no:Bookingen din er fullført via Vipps-betaling"

# Add reusable utility keys
php test_lang_files.php --add-translation --key="check_spam_filter" --module=bookingfrontend --langs="en:Please check your spam filter if you are missing mail.,no:Vi gjør oppmerksom på at e-postsvar blir automatisk generert og derfor noen ganger vil ende i spamfilter/nettsøppel."
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

### Searching for Translation Values

Search for translations containing specific text:

```
php test_lang_files.php --search-value="booking has been registered"
```

Search for values in specific languages:

```
php test_lang_files.php --search-value="Please check your Spam Filter" --langs=en,no,nn
```

This helps when you know the text content but need to find the translation key, or when you want to ensure consistency in terminology across the application.

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