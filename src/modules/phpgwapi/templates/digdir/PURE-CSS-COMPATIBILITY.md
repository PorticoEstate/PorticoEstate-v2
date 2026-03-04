# Pure CSS + Digdir Designsystemet Compatibility Guide

## Overview

PorticoEstate currently uses **Pure CSS** (from Yahoo) alongside the new **Digdir Designsystemet**. This guide explains how they coexist and how to handle conflicts.

## CSS Load Order

The stylesheets are loaded in this specific order:

```
1. Pure CSS (base layer)
   - global.css
   - pure-min.css
   - pure-extension.css
   - grids-responsive-min.css

2. Design System Layer
   - @digdir/designsystemet-css (when template_set = 'digdir')
   - designsystemet-compat.css (compatibility layer)
   OR
   - bootstrap.min.css (when template_set != 'digdir')

3. Icon Fonts
   - FontAwesome
   - Flag Icons

4. Template-specific CSS
   - base.css
   - sidebar.css
   - navbar CSS

5. Theme CSS
   - User's selected theme
```

## Known Conflicts & Solutions

### 1. Grid Systems

**Pure CSS Grid:**
```html
<div class="pure-g">
    <div class="pure-u-1-2">Half width</div>
    <div class="pure-u-1-2">Half width</div>
</div>
```

**Designsystemet/Bootstrap Grid:**
```html
<div class="row">
    <div class="col-6">Half width</div>
    <div class="col-6">Half width</div>
</div>
```

**Solution:** Both can coexist! Just don't mix them in the same container.

✅ **Good:**
```html
<div class="pure-g">
    <div class="pure-u-1">
        <div class="row">
            <div class="col-6">Bootstrap inside Pure</div>
        </div>
    </div>
</div>
```

❌ **Bad:**
```html
<div class="pure-g row">  <!-- Don't mix! -->
    <div class="pure-u-1 col-12">Conflict!</div>
</div>
```

### 2. Buttons

**Pure CSS Buttons:**
```html
<button class="pure-button">Pure Button</button>
<button class="pure-button pure-button-primary">Primary</button>
```

**Designsystemet Buttons:**
```html
<!-- Using component -->
{% include '@components/button.twig' with {
    content: 'Digdir Button',
    variant: 'primary'
} %}

<!-- Or using classes -->
<button class="btn btn-primary">Bootstrap-style</button>
```

**Solution:** The compatibility layer ensures `.btn` classes work correctly. You can use both styles in the same page.

### 3. Forms

**Pure CSS Forms:**
```html
<form class="pure-form pure-form-stacked">
    <input type="text" class="pure-input-1-2">
</form>
```

**Designsystemet Forms:**
```html
<form>
    <input type="text" class="form-control">
</form>
```

**Solution:** Use `.form-control` for Designsystemet styling. Pure CSS form classes are overridden when necessary.

### 4. Tables

**Pure CSS Tables:**
```html
<table class="pure-table pure-table-bordered">
    <tr><td>Data</td></tr>
</table>
```

**Designsystemet Tables:**
```html
<table class="table table-bordered">
    <tr><td>Data</td></tr>
</table>
```

**Solution:** Both work independently. Choose based on your needs.

## Migration Strategy

### Phase 1: Current State (Hybrid Approach)
- Keep Pure CSS for existing pages
- Use Designsystemet for new components
- Let compatibility layer handle conflicts

### Phase 2: Gradual Replacement
Replace Pure CSS classes as you update pages:

**Before:**
```html
<div class="pure-g">
    <div class="pure-u-1">
        <button class="pure-button pure-button-primary">Save</button>
    </div>
</div>
```

**After:**
```html
<div class="row">
    <div class="col-12">
        {% include '@components/button.twig' with {
            content: 'Save',
            variant: 'primary'
        } %}
    </div>
</div>
```

### Phase 3: Optional - Remove Pure CSS
Once all pages are updated:
1. Comment out Pure CSS includes in `head.inc.php`
2. Test thoroughly
3. Remove Pure CSS files if no longer needed

## Best Practices

### ✅ DO:
- Use Pure CSS for legacy pages that aren't being updated
- Use Designsystemet components for new features
- Test pages with both template sets (`bootstrap` and `digdir`)
- Keep CSS load order as specified

### ❌ DON'T:
- Mix Pure grid classes with Bootstrap grid classes on same element
- Override Designsystemet design tokens with hardcoded values
- Load additional CSS frameworks without testing conflicts
- Assume classes from one system work in another

## Specificity Rules

The compatibility layer uses these specificity levels:

```css
/* Level 1: Pure CSS (base) */
.pure-button { }

/* Level 2: Designsystemet (overrides) */
.btn { }

/* Level 3: Compatibility layer (adjustments) */
.btn.pure-button { }

/* Level 4: Important overrides (only when necessary) */
.form-control:focus {
    border-color: var(--ds-color-primary) !important;
}
```

## Common Issues & Fixes

### Issue 1: Buttons look wrong
**Problem:** Pure CSS button styling conflicts with Designsystemet

**Fix:**
```html
<!-- Instead of mixing -->
<button class="pure-button btn btn-primary">Mixed</button>

<!-- Choose one -->
<button class="btn btn-primary">Designsystemet</button>
<!-- OR -->
<button class="pure-button pure-button-primary">Pure CSS</button>
```

### Issue 2: Grid not aligning
**Problem:** Pure grid percentages vs Bootstrap fractions

**Fix:**
```html
<!-- Use consistent grid system per container -->
<div class="pure-g">  <!-- Pure container -->
    <div class="pure-u-1-3">33%</div>
    <div class="pure-u-2-3">66%</div>
</div>

<div class="row">  <!-- Bootstrap container -->
    <div class="col-4">33%</div>
    <div class="col-8">66%</div>
</div>
```

### Issue 3: Forms styled inconsistently
**Problem:** Pure form styles mixed with Bootstrap form classes

**Fix:**
```html
<!-- Wrap Pure forms to isolate them -->
<div class="pure-form-wrapper">
    <form class="pure-form">
        <input type="text" class="pure-input-1">
    </form>
</div>

<!-- Or use Designsystemet throughout -->
<form>
    <input type="text" class="form-control">
</form>
```

## Performance Considerations

**Current Setup:**
- Pure CSS: ~17KB (minified)
- Designsystemet CSS: ~50KB (minified)
- Compatibility Layer: ~15KB
- **Total:** ~82KB CSS

**Optimization Options:**
1. **Keep as is** - Good for gradual migration
2. **Conditionally load** - Load Pure CSS only on legacy pages
3. **Build custom Pure CSS** - Include only used components

## Testing Checklist

When updating pages, test with:

- [ ] `template_set = 'digdir'` - Designsystemet active
- [ ] `template_set = 'bootstrap'` - Bootstrap fallback
- [ ] `template_set = 'portico'` - Legacy template
- [ ] Different screen sizes (mobile, tablet, desktop)
- [ ] Different browsers (Chrome, Firefox, Safari, Edge)
- [ ] Forms submission and validation
- [ ] Button interactions
- [ ] Grid responsiveness

## Tools & Debugging

### Chrome DevTools Inspection
```javascript
// Check which CSS rule is applied
getComputedStyle(document.querySelector('.btn')).backgroundColor

// Find conflicting rules
document.querySelectorAll('[class*="pure-"]').forEach(el => {
    console.log(el.className, getComputedStyle(el).display);
});
```

### CSS Specificity Calculator
Use browser DevTools to see which rule wins:
1. Inspect element
2. Look at "Styles" panel
3. Strikethrough rules are overridden

## Resources

- **Pure CSS Docs:** https://purecss.io/
- **Designsystemet Docs:** https://www.designsystemet.no/
- **PorticoEstate Digdir Template:** [README.md](README.md)

## Summary

The Pure CSS + Designsystemet hybrid approach works well for **gradual migration**. The compatibility layer handles most conflicts automatically. Follow best practices to avoid mixing incompatible patterns.

**Key Takeaway:** Use one system per component/container, and both frameworks can coexist peacefully! 🎨
