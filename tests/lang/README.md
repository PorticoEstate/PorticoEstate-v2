# tests/lang: translation usage check for the Next client

`test_lang_files.php --compare` compares the languages **to each other**. A key that is
missing from *all* of them passes it, and the customer sees the raw key. `extract_keys.py`
is the other direction: it finds every key the **client source references** and checks that
each one resolves in the payload the running app actually **serves**.

Python 3 standard library only, read-only (writes only the JSON path you give it).

## Run

```bash
# 1. Fetch the SERVED payload (not the .lang files: a .lang row only counts once it is installed)
mkdir -p /tmp/lang
for l in no nn en; do curl -sk "https://pe-api.test/bookingfrontend/lang/$l" -o "/tmp/lang/$l.json"; done

# 2. Extract + resolve
python3 -I tests/lang/extract_keys.py src/modules/bookingfrontend/client/src /tmp/lang /tmp/keys.json

# 3. List what does not resolve (in at least one language), with the call sites
jq -r '.by_key | to_entries[]
       | select([.value.res[] | .status] | any(. != "FOUND"))
       | "\(.key)\t\([.value.res[] | .status[0:1]] | join(""))\t\(.value.sites | join(" ; "))"' /tmp/keys.json | sort
```

The second column is the status in `no`, `nn`, `en` (`F` found, `M` missing, `E` empty).
The script itself exits 0 whatever it finds; it reports, it does not gate.

## What a hit means

The output is **candidates, not defects**. Read the call site. At the commit that introduced
this tool, 46 keys did not resolve: 27 were real raw keys on customer screens, 19 were false
positives of the classes below.

## Resolution model

Mirrors i18next as the client configures it: `t('module.key')` resolves to
`payload['module']['key']`, with **no fallback to `common`**. A key resolves only if that
value is a non-empty string, so `bookingfrontend.x` and `common.x` are different keys.

## What it CANNOT see (blind spots)

Each line says how it is known. **DEMONSTRATED** = reproduced with the fixture at the bottom;
**READ** = taken from the code, not run.

| Blind spot | Effect | Known by |
|---|---|---|
| Dynamic keys: `` t(`bookingfrontend.${x}`) ``, `t(a + '.description')`, `t(variable)` | Recorded as `dynamic-*`, **never resolved**. A missing key behind one is invisible. The literal fragment of a concatenation (`.description`) is resolved on its own, which is a false positive | DEMONSTRATED |
| Keys that never appear in client TS: thrown by the node gateway as `translationKey`, sent by PHP, menuaction strings | Invisible (the 7 booking errors in `booking.service.ts` were found by hand, not by this tool) | READ |
| Comments, except a whole line starting with `//`, `*` or `/*` | A trailing `// t('x')`, a JSX `{/* t('x') */}` and a block-comment line without a leading `*` are **counted as use** (false positive) | DEMONSTRATED |
| Dead code: a component with no importers, an unreachable branch | Counted as used (false positive) | READ (the 6 dead keys of the first run) |
| Strings that look like keys but are not: `bookingfrontend.uibooking.edit` and the other menuaction values | Any string literal starting with `bookingfrontend.`, `common.`, `booking.`, `login.`, `preferences.`, `home.`, `admin.` or `messenger.` is treated as a key (false positive) | DEMONSTRATED |
| A key reached through an alias or table (`tr('x')`, a constant) | Found **only if** it starts with one of the module prefixes above; a bare key with another prefix is missed | DEMONSTRATED (the prefixed arm) |
| Files other than `.ts` / `.tsx` (`.js`, `.mdx`), `node_modules`, `.next` | Not scanned | DEMONSTRATED (`.js`) |
| i18next plural/context suffixes (`_one`, `_other`, `_plural`) and interpolation arguments | Only the base key is checked; a missing plural form or a wrong `{{placeholder}}` passes | READ |
| **Render sinks that never call `t()`** (`{errors.end.message}` where the message is a key, `placeholder={someKey}`, `dangerouslySetInnerHTML={{__html: message.text}}`) | The key string appears nowhere as a `t('...')` argument, or it appears in a schema file as a plain string, so it is invisible here while the **key exists in every payload and still shows raw on screen**. This is the class the first run missed (`application-crud.tsx`, the end-time error) | READ (found by a second reviewer, then driven live) |
| Hard-coded English or Norwegian in the UI (`message: 'Interval must be 1, 2, 3, or 4 weeks'`) | Not a key, so never looked up | READ |
| Correct **text** | Only "a non-empty string exists". A wrong or misspelled translation passes | READ |
| Stale payload | It checks what is *served now*. A row in a `.lang` file that has not been installed (`update_language_files.sh`) is reported missing; a row that was installed but is no longer in the file is reported found | READ |

A green run therefore means "every static, key-shaped reference resolves in the served payload".
It does not mean "no customer can see a raw key".

## Fixture used for the DEMONSTRATED rows

`probe.tsx` (the first line is the positive control and must **not** be reported):

```tsx
const p0 = t('common.fixture_present');                  // present in the payload below
const a = t('common.fixture_inline'); // t('common.fixture_trailing_comment')
const j = <div>{/* t('common.fixture_jsx_comment') */}</div>;
// t('common.fixture_line_comment')                      // NOT reported: whole-line comment
/*
   t('common.fixture_block_no_star')
*/
const b = t(`bookingfrontend.${x}`);                     // dynamic-template, never resolved
const c = t(cond ? 'common.fixture_cond_a' : 'common.fixture_cond_b');
const d = tr('common.fixture_alias_tr');
const e = i18n.t('common.fixture_i18n');
const f = foo.t('common.fixture_member');
const g = t('bookingfrontend.fixture_plural', {count: 2});
const h = ('common.fixture_bare_literal_in_table');
const k = t('uncommon-module.fixture_unknown_module');
```

with the payload `{"common":{"fixture_present":"x"},"bookingfrontend":{},"booking":{}}` as
`no.json`, `nn.json` and `en.json`, and a `probe.js` holding `t('common.fixture_in_js_file')`.
Expected: 12 keys not found (everything above except `fixture_present` and `fixture_line_comment`),
`probe.js` not scanned, the `${x}` template listed under `kinds.dynamic-template`.

## Fixing a raw key by wrapping the render in `t()`

Check first that the value is a **key**, not already translated text. `t()` of text that is not
a key returns `TRANSLATION MISSING FOR "<text>"` on the **first** call and the bare text on every
later call (real i18next, the client's own options, the served `no`/`en` payload):

```
t("Sluttidspunktet må være etter starttidspunktet")  1st: TRANSLATION MISSING FOR "Sluttidspunktet må …"   2nd: Sluttidspunktet må …
```

so wrapping a message that some code path has already passed through `t()` swaps one visible
defect for another on the first render.

## Before you add a translation

Use `php test_lang_files.php --add-translation` (never edit a `.lang` file by hand), install,
re-fetch the payload, re-run this check. The installer rebuilds the table from the files, so a
row that exists only in the database does not survive it.
