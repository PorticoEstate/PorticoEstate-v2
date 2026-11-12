# Legacy `sfValidator*` → Symfony Validator 7.4 LTS Migration Plan

Status: **Planned — not started**

## 1. Background

The booking module bundles a copy of the symfony 1.x form validators under
`src/modules/booking/inc/vendor/symfony/validator/` (loaded manually through
`bootstrap.php`). That code is unmaintained, predates modern PHP, and is
instantiated through a special case in `booking_ofbooking::createObject()`
(`src/modules/booking/inc/class.ofbooking.inc.php`).

Despite living in booking, the validators are used by **several modules**:
booking, bookingfrontend, eventplanner, eventplannerfrontend, rental, property
and the shared `phpgwapi` model base class.

### Target

- `symfony/validator:^7.4` (LTS; PHP ≥ 8.2, bug fixes to Nov 2028, security
  fixes to Nov 2029). Used **standalone** — no Symfony framework, no Slim changes.
- A small project-owned validation layer (`App\Validation\...` or a
  `phpgwapi` service) that hides Symfony from call sites and keeps the
  existing error-stack / translation behaviour.
- Project-owned constraints for Norwegian identifiers that **deliberately
  preserve** current business rules (see §4).

### Non-goals

- Changing identifier policy (tightening or loosening) — explicitly out of scope.
- Reworking forms/UI. Only the validation engine is replaced.
- Migrating to Symfony Forms.

## 2. Current usage inventory

Validators actually used by application code:

| Legacy validator | Approx. uses | Replacement |
|---|---|---|
| `sfValidatorEmail` | 17 | Custom `LegacyEmail` constraint (regex-preserving) or `Assert\Email` + regex |
| `sfValidatorNorwegianOrganizationNumber` | 18 | Custom `NorwegianOrganizationNumber` constraint |
| `sfValidatorNorwegianSSN` | 15 | Custom `NorwegianSsn` constraint |
| `sfValidatorFile` | 2 | `Assert\File` + small upload value object |
| `sfValidatorError` (catch) | ~25 sites | `ValidationFailedException` from the adapter |

### 2.1 Entity field definitions (`'sf_validator' => ...`)

Validated generically by a shared loop:

- `src/modules/booking/inc/class.socommon.inc.php` — `_validate()` (~L1345–1410)
- `src/modules/phpgwapi/inc/model/class.model.inc.php` (~L418–425)
- `src/modules/rental/inc/model/class.model.inc.php` (~L366–373)

Field definitions:

- booking: `class.soapplication`, `class.socompleted_reservation`,
  `class.socontact_organization`, `class.sodelegate`, `class.soevent`,
  `class.sogroup`, `class.soorganization`, `class.soparticipant`, `class.souser`
- eventplanner models: `application`, `booking`, `customer`, `events`, `vendor`
- rental model: `application`

All three loops do the same thing: skip empty values, `setOption('required', false)`,
call `clean()`, **discard the return value**, and on `sfValidatorError`
replace `%field%`, run `lang()`, and push a field-keyed error.

### 2.2 Direct calls (return value or exception used)

| File | Line(s) | Validator | Uses return value? |
|---|---|---|---|
| `booking/inc/class.sodelegate.inc.php` | ~56 | SSN | no |
| `booking/inc/class.souser.inc.php` | ~289, ~576 | SSN, Org | no |
| `booking/inc/class.uiapplication.inc.php` | ~3014 | Org | **yes** |
| `booking/inc/class.sodocument.inc.php` | ~176–183 | File | **yes** (`getOriginalName()`, save) |
| `booking/inc/class.sodocumentation.inc.php` | ~111 | File | **yes** |
| `bookingfrontend/helpers/UserHelper.php` | ~77, ~657, ~857 | Org, Org, SSN | **yes** (Org) |
| `bookingfrontend/inc/class.uiorganization.inc.php` | ~69 | Org | **yes** |
| `bookingfrontend/inc/custom/default/MinId2.php` | ~101, ~153 | Org | **yes** |
| `bookingfrontend/services/OrganizationService.php` | ~155, ~161, ~204 | Org, SSN | check |
| `bookingfrontend/services/applications/ApplicationService.php` | ~1007, ~1041, ~1055 | Email, Org, SSN | check |
| `eventplannerfrontend/inc/class.hook_helper.inc.php` | ~146 | Org | **yes** |
| `eventplannerfrontend/inc/class.bouser.inc.php` | ~236 | Org | **yes** |
| `property/inc/class.uilocation.inc.php` | ~2676–2682 | SSN | no |

Re-run before starting, line numbers drift:

```bash
grep -rnE "sfValidator|sf_validator" src --include=*.php | grep -v "inc/vendor/symfony"
```

## 3. Target design

```
src/modules/phpgwapi/services/Validation/      (or another shared location)
├── Validator.php                 # thin facade around Symfony ValidatorInterface
├── ValidationFailedException.php # carries message + params, replaces sfValidatorError
├── FieldRule.php                 # value object stored in entity field definitions
└── Constraints/
    ├── LegacyEmail.php + LegacyEmailValidator.php
    ├── NorwegianOrganizationNumber.php + ...Validator.php
    └── NorwegianSsn.php + ...Validator.php
```

### 3.1 Facade API (call-site friendly)

```php
// Returns the normalised value or throws ValidationFailedException
$orgnr = Validator::clean('org_number', $value, required: true);

// Returns list<string> of untranslated messages with %field% placeholder
$errors = Validator::check('email', $value);
```

- `clean()` mirrors legacy semantics (return cleaned value, throw on error) so
  direct call sites change with minimal edits.
- Symfony returns violation lists, not cleaned values. The facade must return
  the **same string** the legacy validator returned (legacy `sfValidatorString`
  casts to string; `trim` is **off** by default — do not start trimming silently).
- Messages keep the existing English keys (e.g. `%field% contains an invalid
  organization number`) so current `lang()` translations keep working.

### 3.2 Entity field definitions

Replace `'sf_validator' => createObject('booking.sfValidatorEmail', ...)` with a
declarative rule, e.g. `'validator' => FieldRule::email()` /
`FieldRule::orgNumber()` / `FieldRule::ssn()`, optionally with a custom message.
The three shared loops call the facade and keep:

- skip when empty (`required=false` semantics),
- `%field%` substitution + `lang()`,
- field-keyed error stack, including **nested field paths** (booking recurses).

During transition, the loops should accept **both** `sf_validator` and
`validator` keys so modules can migrate independently.

## 4. Behaviour that MUST be preserved exactly

### 4.1 Norwegian organization number (deliberately accepted quirks)

Current rule (`sfValidatorNorwegianOrganizationNumber`):

1. Value must match `/(^\d{9}$)|(^\d{6}$)|(^\d{5}$)/`.
2. **5-digit** values are accepted without checksum.
3. **6-digit** values are accepted without checksum.
4. **9-digit** values must pass modulus-11 (weights 3,2,7,6,5,4,3,2 from the
   left / 2..7 cycling from the right; remainder 0 → control 0; computed 10 →
   always invalid).
5. **`000000000` is explicitly accepted** (bypasses checksum).
6. No trimming; `" 123456789"` is invalid. Other lengths (7, 8, 10, 11…) invalid.
7. Empty value + `required=false` → accepted, returns empty value (`null`).

These are **intentional business exceptions** (legacy/foreign/internal
identifiers and placeholder orgs) and must be kept as-is. Document them in the
constraint class docblock so nobody "fixes" them later.

### 4.2 Norwegian SSN

Current rule (`sfValidatorNorwegianSSN`):

1. Must match `^(0[1-9]|[12]\d|3[01])([04][1-9]|[15][0-2])\d{7}$` — 11 digits,
   day 01–31 (no D-numbers), month 01–12 or 41–52 (H-numbers).
2. `full_required=false` currently uses **the same 11-digit regex**; the
   "6 or 11 digits" message (`invalid2`) is never used. Preserve: 6-digit input
   is **rejected** regardless of `full_required`.
3. Only the **second control digit (K2)** is checked by modulus-11; K1 is not.
   Preserve; do not add K1 checking in this migration.
4. No trimming.

Any policy change (D-numbers, synthetic test numbers with month +80, K1 check,
6-digit support) is a **separate, follow-up decision**.

### 4.3 Email

Legacy regex: `/^([^@\s]+)@((?:[-a-z0-9]+\.)+[a-z]{2,})$/i`.
Symfony `Assert\Email` (html5 mode) differs (e.g. accepts dotless/IDN hosts
differently). Use a `LegacyEmail` constraint with the same regex, or compose
`Assert\Regex` with the legacy pattern. Do **not** switch to `Assert\Email`
semantics in this migration.

### 4.4 File

`sfValidatorFile::clean()` returns an `sfValidatedFile` object used for
`getOriginalName()` and saving. Provide a small `ValidatedUpload` value object
with the same methods used by `sodocument`/`sodocumentation`, backed by
`Assert\File` for size/mime checks (keep current limits — check the
validator options used at the call sites first).

## 5. Step-by-step checklist

### Phase 0 — Preparation

- [ ] Re-run the inventory grep (§2) and update the tables above.
- [ ] Confirm whether any custom/template overrides (`inc/custom/*`) or
      external plugins call `sfValidator*`.
- [ ] Record each direct call site's dependency on the return value.

### Phase 1 — Characterisation tests (against the **legacy** validators)

Create `tests/Validation/LegacyValidatorBehaviourTest.php` driven by data
providers, run it against the current `sfValidator*` classes first so it
documents today's behaviour. The same data sets will later run against the new
constraints.

Organization number — **must pass**:
- [ ] Valid 9-digit with correct mod11 (e.g. a known real org number).
- [ ] **`000000000` accepted.**
- [ ] **Any 5-digit value accepted (e.g. `12345`, `00000`).**
- [ ] **Any 6-digit value accepted (e.g. `123456`, `000000`).**
- [ ] Empty string / `null` accepted when `required=false`.

Organization number — **must fail**:
- [ ] 9-digit with wrong control digit.
- [ ] 9-digit where computed control is 10.
- [ ] 7, 8, 10 and 11-digit values.
- [ ] Values with spaces, letters or leading/trailing whitespace.
- [ ] Empty value when `required=true`.

SSN:
- [ ] Valid 11-digit (correct K2) accepted; H-number month (41–52) accepted.
- [ ] Wrong K2 rejected; wrong K1 but correct K2 **accepted** (documents quirk).
- [ ] 6-digit rejected with `full_required=true` **and** `false`.
- [ ] Day 00/32, month 00/13, D-number day (41–71) rejected.

Email:
- [ ] Legacy regex accept/reject matrix (simple, subdomains, plus-tag,
      uppercase, missing TLD, whitespace, double `@`).

Shared loop:
- [ ] Optional empty field produces no error.
- [ ] Invalid field produces translated message with `%field%` substituted.
- [ ] Nested field errors keep their nested key path.

### Phase 2 — Add dependency and validation layer

- [ ] `composer require symfony/validator:^7.4` (verify lock resolves on PHP 8.2
      and that the PHP 8.5 image still builds).
- [ ] Implement facade, exception and `FieldRule` (§3).
- [ ] Implement `NorwegianOrganizationNumber` constraint that
      **deliberately accepts 5-digit, 6-digit and `000000000`**, with a docblock
      explaining these are intentional business exceptions.
- [ ] Implement `NorwegianSsn` constraint preserving §4.2 exactly.
- [ ] Implement `LegacyEmail` constraint with the legacy regex.
- [ ] Implement `ValidatedUpload` + file rule.
- [ ] Run the Phase 1 data sets against the new constraints — results must be
      identical to the legacy run (same accept/reject, same returned value, same
      message key).

### Phase 3 — Shared loops (dual-mode)

- [ ] `booking_socommon::_validate()` accepts `validator` (new) and
      `sf_validator` (legacy).
- [ ] Same for `phpgwapi` model and `rental` model base classes.
- [ ] Shared-loop tests from Phase 1 pass for both keys.

### Phase 4 — Migrate field definitions (module by module)

- [ ] booking `so*` classes.
- [ ] eventplanner models.
- [ ] rental `application` model.
- [ ] After each module: run its tests + manual smoke test of create/edit forms
      with an invalid email, a 5-digit org, a 6-digit org, `000000000`, and an
      invalid SSN.

### Phase 5 — Migrate direct call sites

- [ ] booking: `sodelegate`, `souser`, `uiapplication`, `sodocument`,
      `sodocumentation`.
- [ ] bookingfrontend: `UserHelper`, `uiorganization`, `MinId2`,
      `OrganizationService`, `ApplicationService`.
- [ ] eventplannerfrontend: `hook_helper`, `bouser`.
- [ ] property: `uilocation`.
- [ ] Replace every `catch (sfValidatorError $e)` with
      `catch (ValidationFailedException $e)`; verify messages still go through
      `lang()` where they did before.
- [ ] Session org-number flows (login/MinId/ID-porten) re-tested with 5-, 6-
      and 9-digit identifiers and `000000000`.

### Phase 6 — Remove legacy code

- [ ] Grep shows zero `sfValidator` / `sf_validator` references outside the
      vendor directory.
- [ ] Remove `sf_validator` support from the three shared loops.
- [ ] Remove the `sfValidator` special case in `booking_ofbooking::createObject()`.
- [ ] Delete `src/modules/booking/inc/vendor/symfony/validator/`.
- [ ] Remove now-unused `sfValidator*` translation keys only if not referenced.

### Phase 7 — Verification

- [ ] Full test suite on PHP 8.2 (host) and PHP 8.5 (`html-slim` image).
- [ ] `composer phpcompat` shows no new findings.
- [ ] `vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 8.5-8.5 src/modules/booking src/modules/bookingfrontend src/modules/eventplanner src/modules/eventplannerfrontend src/modules/rental`
- [ ] Final regression: 5-digit org ✅, 6-digit org ✅, `000000000` ✅,
      valid 9-digit ✅, invalid 9-digit ❌, 7/8-digit ❌.

## 6. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Silent policy change in identifiers | Characterisation tests run against both implementations; quirks documented in constraint docblocks |
| Call sites relying on returned cleaned value | Facade `clean()` returns identical value; inventory column "uses return value" |
| Translation keys change | Reuse exact legacy message strings |
| Nested error paths lost | Explicit shared-loop test for nested fields |
| Upload handling regression | `ValidatedUpload` exposes the same methods; manual document upload test |
| Partial migration breaks other modules | Dual-mode shared loops; legacy code removed only in Phase 6 |

## 7. Alternatives considered

- **Respect/Validation** — maintained and lightweight, but smaller ecosystem and
  less common in the PHP world than Symfony Validator.
- **Native PHP + project-owned rules** — no dependency, but re-implements
  messages, composition and file validation already provided by Symfony.
- **Keep bundled symfony 1.x code** — rejected: unmaintained, legacy idioms,
  ongoing PHP-version compatibility risk.
