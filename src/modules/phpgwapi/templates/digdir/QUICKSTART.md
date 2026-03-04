# Digdir Designsystemet Template - Quick Start Guide

## 🚀 Getting Started in 5 Minutes

### Step 1: Enable the Template

Edit your configuration file (usually `/config/header.inc.php`):

```php
$GLOBALS['phpgw_info']['server']['template_set'] = 'digdir';
```

Or set it through the admin interface:
1. Login as administrator
2. Go to **Admin** → **Site Configuration**
3. Find **Template Selection**
4. Select **digdir**
5. Save

### Step 2: Clear Cache

```bash
cd /var/www/html
rm -rf src/cache/twig/*
```

### Step 3: Verify Installation

Visit your PorticoEstate site. You should see:
- New design with Digdir styling
- Designsystemet web components (if template is active)
- Bootstrap fallback (if template_set is not 'digdir')

## 🎨 Quick Examples

### Example 1: Simple Button

```twig
{% include '@components/button.twig' with {
    content: 'Click Me',
    variant: 'primary'
} %}
```

### Example 2: Alert Message

```twig
{% include '@components/alert.twig' with {
    content: 'Operation successful!',
    variant: 'success',
    dismissible: true
} %}
```

### Example 3: Card Component

```twig
{% include '@components/card.twig' with {
    title: 'User Profile',
    content: '<p>Name: John Doe</p><p>Email: john@example.com</p>'
} %}
```

### Example 4: Using PHP

```php
use App\modules\phpgwapi\services\DesignSystem;

$ds = DesignSystem::getInstance();

// Render a button
echo $ds->component('button', [
    'content' => 'Save',
    'variant' => 'primary',
    'type' => 'submit'
]);

// Check if active
if ($ds->isEnabled()) {
    echo "Designsystemet is active!";
}
```

## 🔧 Common Tasks

### Adding a Custom Color

Edit `/src/modules/phpgwapi/templates/digdir/css/custom.css`:

```css
:root {
    --my-custom-color: #ff6600;
}

.btn-custom {
    background-color: var(--my-custom-color);
    color: white;
}
```

### Creating a Custom Component

1. Create `/src/modules/phpgwapi/templates/digdir/components/mycomponent.twig`:

```twig
{% if is_designsystemet %}
    <ds-mycomponent {{ attributes }}>
        {{ content|raw }}
    </ds-mycomponent>
{% else %}
    <div class="my-bootstrap-component">
        {{ content|raw }}
    </div>
{% endif %}
```

2. Use it in your templates:

```twig
{% include '@components/mycomponent.twig' with {
    content: 'Hello World'
} %}
```

### Switching Between Templates

```php
// Use Digdir
$GLOBALS['phpgw_info']['server']['template_set'] = 'digdir';

// Use Bootstrap (fallback)
$GLOBALS['phpgw_info']['server']['template_set'] = 'bootstrap';
```

## 📝 Testing Your Setup

### Test Page

Create a test file at `/var/www/html/test-digdir.php`:

```php
<?php
require_once 'header.inc.php';

use App\modules\phpgwapi\services\DesignSystem;
use App\modules\phpgwapi\services\Twig;

$ds = DesignSystem::getInstance();
$twig = Twig::getInstance();

echo $twig->render('example.twig', [
    'site_title' => 'Test Page',
    'userlang' => 'en',
    'javascripts' => [],
    'stylesheets' => $ds->getStylesheets()
]);
```

Visit: `http://your-site/test-digdir.php`

## 🐛 Troubleshooting

### Issue: Components not showing

**Solution:**
```bash
# Check template setting
php -r "require 'header.inc.php'; echo \$GLOBALS['phpgw_info']['server']['template_set'];"

# Should output: digdir
```

### Issue: Styling looks wrong

**Solution:**
```bash
# Check if CSS files exist
ls -la src/modules/phpgwapi/templates/digdir/css/

# Verify npm packages
npm list @digdir/designsystemet-css @digdir/designsystemet-web
```

### Issue: Twig errors

**Solution:**
```bash
# Clear cache
rm -rf src/cache/twig/*

# Check PHP error log
tail -f /var/log/apache2/error.log
```

## 📚 Next Steps

1. Read the [full README](README.md)
2. Explore the [example template](example.twig)
3. Check the [component documentation](README.md#component-properties)
4. Visit [Digdir Designsystemet docs](https://www.designsystemet.no/)

## 💡 Tips

- Always test with `template_set = 'bootstrap'` first to ensure fallback works
- Use browser DevTools to inspect design tokens
- Check console for web component errors
- Keep Bootstrap classes for backward compatibility

## 🔗 Quick Links

- **Designsystemet Website**: https://www.designsystemet.no/
- **NPM Package**: https://www.npmjs.com/package/@digdir/designsystemet-web
- **GitHub**: https://github.com/digdir/designsystemet
- **PorticoEstate**: https://github.com/PorticoEstate/PorticoEstate-v2

---

**Need Help?** Open an issue on the PorticoEstate repository or check the main README.
