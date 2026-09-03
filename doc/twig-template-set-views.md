# Template-set-specific Twig Views

## Purpose

Application views can provide one shared implementation and optional
 template-set-specific implementations. This is useful when the behavior and
 data contract are shared, but the HTML, CSS, or JavaScript presentation differs
 between template sets such as `base` and `digdir`.

The convention applies to module assets located under:

```text
src/modules/<module>/html/
```

## Directory convention

Use one directory per function/view below `base` and below a named template set:

```text
src/modules/booking/html/
├── base/
│   ├── application/show/
│   ├── components/
│   └── hospitality/show/
├── digdir/
│   ├── application/show/
│   └── components/
└── _shared/
```

The usual layout is:

```text
src/modules/<module>/html/base/<view>/
src/modules/<module>/html/<template_set>/<view>/
```

For example:

```text
src/modules/booking/html/base/application/show/application_show.twig
src/modules/booking/html/digdir/application/show/application_show.twig
```

`base` is the generic implementation. A named template-set directory contains
only the files that need a different presentation or implementation.

## Normal `@views` lookup

`TwigHelper` registers the app HTML directories in this order for the `views`
namespace:

1. `html/<template_set>/`
2. `html/base/`
3. `html/`

A reference such as:

```twig
{% include '@views/application/show/application_show.twig' %}
```

therefore selects the template-set-specific file when it exists, and otherwise
falls back to the generic base file. Existing modules that still use the flat
`html/<view>/` layout continue to work through the final fallback path.

The controller should normally reference only the logical `@views/...` path:

```php
$this->twig->render('@views/application/show/application_show.twig', $data);
```

No controller change is needed when a template-set override is added.

## Explicit asset namespaces

The same logical filename can exist in both `base` and a template-set folder.
For assets loaded with Twig `source()`, explicit namespaces are available:

```text
@base_views/...
@<template_set>_views/...
```

For `template_set = digdir`, use:

```twig
{{ source('@base_views/application/show/application_show.js') }}
{{ source('@digdir_views/application/show/application_show.js') }}
```

`TwigHelper` registers these namespaces as follows:

```php
$this->addPathIfExists($appDir . '/html/base', 'base_views');
$this->addPathIfExists($appDir . '/html/' . $templateSet, $templateSet . '_views');
```

Use explicit namespaces when a wrapper must load its own CSS or JavaScript.
This avoids accidentally resolving the template-set version through the
prioritized `@views` namespace.

## Shared partials

A partial that contains common data and DOM structure can be placed in a shared
folder, for example:

```text
src/modules/booking/html/base/_shared/application_show_body.twig
```

Both wrappers can include it through the normal `@views` namespace:

```twig
{% include '@views/_shared/application_show_body.twig' %}
```

The shared partial should contain the stable contract:

- root element IDs
- `data-*` attributes
- translated values passed to JavaScript
- form field names and IDs
- tab names and panel IDs
- API-related markup and state containers

It should not load CSS or JavaScript. Those assets belong in the wrapper so
that each template set can load its own implementation.

## Presentation parameters

When the common partial needs different CSS class names, pass them as
parameters instead of duplicating the entire partial:

```twig
{% include '@views/_shared/application_show_body.twig' with {
    spinner_class: 'ds-spinner',
    alert_class: 'ds-alert',
    paragraph_class: 'ds-paragraph',
    tabs_class: 'ds-tabs'
} %}
```

The base partial should define generic defaults:

```twig
{% set spinner_class = spinner_class|default('booking-spinner') %}
{% set alert_class = alert_class|default('booking-alert') %}
{% set paragraph_class = paragraph_class|default('booking-paragraph') %}
{% set tabs_class = tabs_class|default('booking-tabs') %}
```

Keep the values used by JavaScript and CSS aligned with the wrapper that loads
that JavaScript file.

## JavaScript rule

JavaScript files are not automatically selected by the Twig template lookup if
they are loaded with `source()` from a shared wrapper. Load them explicitly:

```twig
{# Generic wrapper #}
<script>{{ source('@base_views/application/show/application_show.js') }}</script>

{# Digdir wrapper #}
<script>{{ source('@digdir_views/application/show/application_show.js') }}</script>
```

A generic JavaScript implementation should use neutral DOM classes and preserve
the shared IDs and `data-*` contract. A template-set-specific implementation
may generate framework-specific markup, for example Designsystemet `ds-*`
classes.

## What belongs in `base`

Put the following in `base` when they are shared across template sets:

- REST/API URLs and request flow
- data loading and error handling
- form semantics and field identifiers
- neutral DOM IDs and `data-*` attributes
- reusable components with a stable, framework-neutral contract
- domain behavior such as filtering, date calculations, and validation

Use neutral class names such as `booking-button`, `booking-table`, and
`booking-dialog` when the base JavaScript generates markup.

## What belongs in a template-set directory

Put the following in `html/<template_set>/` when they depend on a specific UI
framework or visual system:

- framework component classes such as `ds-button` or `ds-table`
- framework-specific CSS tokens such as `--ds-*`
- framework-specific DOM attributes and component structure
- JavaScript that generates or controls framework-specific markup
- template-set-specific layout and interaction behavior

The booking module currently keeps Digdir-specific implementations for
`application/show`, `components/datatable`, `components/hospitality_order_list`,
and `components/hospitality_order_modal` under `html/digdir`, with generic
counterparts under `html/base`.

## Migration checklist

1. Identify the current view path used by the controller.
2. Move the shared implementation to `html/base/<view>/`.
3. Create `html/<template_set>/<view>/` only when the presentation differs.
4. Keep the controller's logical `@views/...` path unchanged.
5. Move common data and DOM structure into a shared partial where duplication is
   substantial.
6. Keep CSS and JavaScript loading in the per-template-set wrapper.
7. Use `@base_views` and `@<template_set>_views` for same-named assets.
8. Check that all JavaScript selectors still match the shared DOM contract.
9. Test both the generic template set and every implemented override.

## Validation

At minimum, validate:

```bash
php -l src/modules/phpgwapi/helpers/TwigHelper.php
node --check src/modules/<module>/html/base/<view>/<file>.js
node --check src/modules/<module>/html/<template_set>/<view>/<file>.js
```

Also load the affected route with a non-Digdir template set and with `digdir`
to confirm that the correct Twig, CSS, and JavaScript variants are selected.
