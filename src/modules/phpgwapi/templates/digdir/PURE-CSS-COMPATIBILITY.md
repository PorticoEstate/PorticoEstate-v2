# Pure CSS + Digdir Designsystemet Compatibility Guide

## Overview

PorticoEstate currently uses **Pure CSS** (from Yahoo) alongside the new **Digdir Designsystemet**. This guide explains how they coexist and how to handle conflicts.

## CSS Load Order

Bootstrap, Digdir and Portico load the shared `phpgwapi/templates/base/css/base.css`
and the current module's `templates/base/css/base.css` when present, before the
template-set overrides. Optional module and theme files are included only when
present.

Pure's global, action-bar, form and grid styles are loaded for all three.
Bootstrap loads Bootstrap CSS once. Digdir loads `digdir-native.css`, its
component and shell styles, then the Designsystemet and admin token assets; the
Designsystemet assets use CSS cascade layers. Widget styles are emitted before
the template styles in all three shells. The Bootstrap Multiselect widget does
not inject Bootstrap's global stylesheet into Digdir or Portico; its dropdown
uses scoped compatibility styles instead. DataTables styles are registered by
the widget loader, not duplicated in the Portico head.

The shared phpgwapi stylesheet retains legacy element defaults for older
templates while Bootstrap and Digdir use their own shell typography. Legacy
module button rules do not override Pure or framework buttons in those two
templates. `designsystemet-compat.css` is not loaded by the Digdir head.
Digdir keeps Pure's responsive grid widths from the upstream responsive
stylesheet; its native styles only provide the flex grid container behavior.
Legacy module-wide form, label, link, and heading defaults are scoped away from
Bootstrap and Digdir so they cannot outrank the framework styles. Portico
includes a viewport declaration for mobile layout.

## Known Conflicts & Solutions

### Home cards and bookmark tiles

The Digdir page shell uses `.app-card` / `.app-card-body`, rather than the
Bootstrap `.card` / `.card-body` classes. The outer `.app-page-card` allows
overflow so form widgets and focus outlines are not clipped.
Breadcrumbs and all `.app-card` containers use the same drop shadow.

Home bookmarks use `.app-bookmark-grid` with three equal-width columns, two at
viewport widths up to 48rem, and one up to 30rem. Tile links fill their grid cells;
flexible card bodies align the footer rows even when icons or captions differ.
These styles live in `css/digdir-native.css`; the Bootstrap rendering is unchanged.

### Shared home hook tables

For all template sets, `HomeHelper` encloses module home hooks in
`#home-hook-content`. The shared `js/core/home.js` wraps each table in a
keyboard-focusable `.home-table-scroll` container. It also handles tables
recreated by pagination or filtering, without nesting or leaving empty wrappers.
The overflow styles live in `templates/pure/css/global.css`, loaded by all three
template sets. Headings, filters, and pagers remain outside the scroll area.

### Legacy aligned forms in the Digdir template

The active loader uses `digdir-native.css`, not `designsystemet-compat.css`.
Digdir's `css/base.css` supplies the aligned-form adjustments without changing
shared XSL templates or the Bootstrap template set:

- Form text, native controls, and single Select2 controls use a consistent scale
  and the current Designsystemet typography and neutral-color tokens.
- Fieldsets may shrink below their contents' intrinsic width. Controls retain
  Pure's fractional widths, capped to leave room for the label column.
- Standalone one-fifth columns in aligned custom grids use their natural width,
  so short location lookup fields do not collapse inside shrink-to-fit wrappers.
- At viewport widths up to 48rem, labels stack above controls and fractional
  controls use the available width.
- DataTables keep their own horizontal scrolling; plain tables directly inside
  `.pure-custom` scroll inside that wrapper.
- Action bars use `.sticky-action-bar` and the shared
  `templates/pure/css/action-bar.css`, loaded by the administrative and legacy
  frontend template sets. Property ticket add and view actions are inside their
  sticky bars, with send-order remaining conditional on access and an existing
  order. Eventplanner and Addressbook retain their existing action elements and
  visibility hooks; initially hidden or disabled bars are not activated. The
  inner `#submitbox` owns the surface styling so hiding it also collapses the
  bar.
- Digdir's remaining legacy action-bar overrides exclude `.sticky-action-bar`.

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
    variant: 'primary',
    is_designsystemet: true
} %}

<!-- Or using the Digdir shell's native classes -->
<button class="app-button app-button-primary">Primary</button>
```

The Digdir head does not load `designsystemet-compat.css`, so Bootstrap `.btn`
classes are not a general Digdir button fallback. Use Pure or the Digdir shell
classes for buttons in this template.

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
