# Allocation price lock and series cascade

How a price typed on one allocation reaches the rest of its recurrence series, what
stops it reaching prices somebody set by hand, and how to check all of it in a browser.

Written from the built code and from the runs described in *§6 How to test it by hand*.
Everything here is about **the legacy admin allocation edit form**
(`?menuaction=booking.uiallocation.edit&id=…`). Where a statement is true only of that
form, it says so — those bounds are the most perishable part of this document and the
most expensive to rediscover.

**Files**

| What | Where |
|---|---|
| Scope decision, dialogs' data, cascade trigger | `src/modules/booking/inc/class.uiallocation.inc.php` |
| Scope queries, counts, the override writer | `src/modules/booking/inc/class.soallocation.inc.php` |
| The two dialogs | `src/modules/booking/js/base/allocation.js` |
| Columns | `src/modules/booking/migrations/m20260821_add_allocation_group_id_and_price_locked_to_allocation.php` |

---

## 1. What the feature does

A case officer allocates a hall to a club every Tuesday evening for a season. That is
not one booking, it is thirty — thirty rows in `bb_allocation` that were created in one
go and that the officer thinks of as *one arrangement*.

Then the price changes. Before this feature the officer had to open all thirty and
retype the price on each one, and there was nothing recording which of them he had
already touched.

Now, when he changes the price on any one of them and saves, the system asks him two
questions:

**First — how far should this price reach?**

> **Prisen gjelder flere tidspunkt**
> Denne tildelingen er ett av flere tidspunkt i samme serie. Hvilke tidspunkt skal få
> den nye prisen?
>
> `Oppdater dette` · `Oppdater fremtidige (2)` · `Oppdater alle (4)` · `Avbryt`

The numbers in brackets are how many *other* occurrences each answer would change. They
come from the database, not from the page.

**Second — and only if there is something to warn about:**

> **Noen tidspunkt har egen pris**
> 2 av 4 tidspunkt har en pris som er satt manuelt. Vil du overskrive disse med den nye
> prisen, eller beholde dem som de er?
>
> `Overskriv alle` · `Behold de manuelle` · `Avbryt`

Some occurrences in a series get their own price on purpose — a cup final, a session
somebody negotiated separately. The system remembers those, and a bulk price change
steps over them unless the officer says otherwise.

`Avbryt` at either dialog writes **nothing at all** — not even the occurrence he was
editing.

---

## 2. How `price_locked` gets set

`bb_allocation.price_locked` is a `smallint`, `NOT NULL DEFAULT 0`. It is **never a form
input**. There is no checkbox. The officer cannot set or clear it directly, and it is
not in `booking_uiallocation::$fields`, so nothing the browser posts can move it.

It is raised in exactly one place: `class.uiallocation.inc.php:1290`, on a save that the
**server** decided was a genuine price change, immediately before the row is written.

There are exactly **three** sites in the whole codebase that write this column.
Re-derive the list with:

```
rg --line-number 'price_locked' src/
```

| Site | What it does |
|---|---|
| `class.uiallocation.inc.php:627` | new allocation: seeded to `0` |
| `class.uiallocation.inc.php:1290` | the officer changed the price on this row: set to `1` |
| `class.soallocation.inc.php:301` | an "Overskriv alle" overwrote this row: cleared to `0` |

### The invariant — read this before changing anything

> **`price_locked = 1` MEANS: this price was set by hand AND STILL DIFFERS from the rest
> of the group. After an overwrite it no longer differs, so THE MARK IS NO LONGER TRUE,
> and "Overskriv alle" clears it.**

The flag is not a history record ("somebody once typed here"). It is a statement about
the row's *present* relationship to its series — a single checkable meaning, and what
keeps the conflict dialog's count honest.

Leaving the flag standing after an overwrite would make the next "Oppdater alle" warn
the officer about occurrences he had *already deliberately resolved* — the feature
reporting its own output back to him as a conflict. This is the same failure shape as a
cascade marking the members it moves, which is why `cascade_group_price()` never raises
the flag on the members it writes: only the **source** of a change is marked.

**A cascade marks the source and nothing else.** After a cascade the source is
`price_locked = 1` and every member it moved is `price_locked = 0`.

### ⚠️ Bound: this is true of the edit form only

**A price set through `PUT /booking/allocations/{id}` records nothing.** No history row,
no `price_locked`, no cascade — the REST route changes `bb_allocation.cost` and leaves
no trace that a human chose the number. Such a price is invisible to the conflict
dialog and **will be silently cascaded over** by the next "Oppdater alle".

Filed as `#15842`; **unowned**. Do not assume any other write path marks anything —
`rg "\['cost'\]\s*="` finds at least seven assignment sites and exactly one of them
raises the flag.

---

## 3. How the cascade decides a price actually changed

The predicate is at `class.uiallocation.inc.php:1275`:

```php
$price_changed = ($effective_cost !== null)
    && ($stored_cost === null || round($effective_cost, 2) !== round($stored_cost, 2));
```

**The baseline is server-side.** `$stored_cost` (`:1193`) is read from the database row
before anything in the request can move it. It is deliberately *not*
`$_POST['cost_orig']`: that is a hidden field, so the client claiming the price changed
would also be supplying the baseline it is compared against. `cost_orig` is not read by
this controller at all — the only occurrence of the string in the file is a comment.
(Its two siblings, `class.uibooking.inc.php:937` and `class.uievent.inc.php:1220`, still
run the old `$_POST['cost'] != $_POST['cost_orig']` predicate. Nobody has filed that.)

**⛔ Do not describe the predicate as "fully server-side".** `$effective_cost` (`:1268`)
splits on whether articles are enabled:

* **articles ON** (the configuration this stack runs) — the cost field is `readonly` and
  filled by `purchase_order_edit.js`, so the posted figure is the browser echoing
  itself. The trustworthy number is the one the purchase order just derived. But
  `compile_purchase_order` takes `ex_tax_price` **straight from the posted
  `selected_articles[]` string** (`:1129`), so the *new* value is still client-supplied.
* **articles OFF** — `purchase_order_edit.js` is never loaded, nothing else writes the
  field, and the posted cost is the officer's own.

So: **server-side baseline, client-supplied new value.** What #982 fixed was the
baseline, which was the actual defect.

Rounding is at the column's own scale (`numeric(10,2)`), so a float artefact cannot read
as a price change.

---

## 4. The two dialogs

### Where the counts come from

`booking.uiallocation.cascade_preview` (`class.uiallocation.inc.php:417`) is a read-only
JSON endpoint. The browser knows which allocation is on screen and nothing else — not
how many occurrences share its group, not which start later, and above all not which
carry a hand-set price. The second dialog asks the officer to overwrite exactly those,
so the numbers are answered from `bb_allocation`, per scope, by
`soallocation::get_group_scope_summary()` (`:229`).

```
GET /index.php?menuaction=booking.uiallocation.cascade_preview&id=498022
```
```json
{"grouped":true,
 "scopes":{"this":  {"total":0,"locked":0,"conflict_body":"…"},
           "future":{"total":2,"locked":0,"conflict_body":"0 av 2 tidspunkt …"},
           "all":   {"total":4,"locked":1,"conflict_body":"1 av 4 tidspunkt …"}},
 "labels":{"scope_title":"Prisen gjelder flere tidspunkt", …}}
```

Two things about that payload are deliberate:

* **The warning sentence is rendered on the server, numbers and all.** The browser never
  does arithmetic on `%1`/`%2`. Those two numbers *are* the content of the warning.
* **The translated strings travel with it.** The client-side `lang()` is DOM-backed and
  fails silently to the raw key, and these dialogs are built entirely in script with
  nothing in the template to read a translation from. Sending the text removes the
  failure mode instead of guarding against it.

**Counts exclude the allocation being edited.** It is the row the officer is already
looking at, not one of the rows he is being warned about. So "2 av 4" on a five-member
series means: of the 4 *other* occurrences in scope, 2 carry a hand-set price.

### What the scopes mean

| Answer | `cascade_scope` | Reaches |
|---|---|---|
| Oppdater dette | `this` | nothing — the source only |
| Oppdater fremtidige | `future` | members with `from_ >= source.from_` |
| Oppdater alle | `all` | every member of the group |

`future` is bounded by the source's **stored** start time (`$stored_from`, `:1201`), not
the one just posted. `cascade_preview` counted against that value, so the cascade must
bound itself by it — otherwise the dialog told the officer something that did not
happen. If he moves the date *and* changes the price in the same save, "fremtidige"
means "from where this occurrence was", not "from where it is going".

### What the buttons do

| Button | Posts | Effect |
|---|---|---|
| Overskriv alle | `cascade_overwrite_locked=1` | unlocked members move as usual; **locked members are also overwritten and their flag is cleared to 0** |
| Behold de manuelle | `cascade_overwrite_locked=0` | locked members are **not touched at all** — no cost change, no history row, flag left at 1 |
| Avbryt | nothing | the form is never submitted; nothing is written |

"Behold de manuelle" is the behaviour that shipped before these dialogs existed. It is
unchanged.

### The two writers, and why there are two

`cascade_group_price()` (`class.uiallocation.inc.php:355`) writes the two populations
through two different mechanisms on purpose:

* **Unlocked members → `bo->update()`.** That is what keeps the three things a bare
  write drops: the cost history row, the webhook notification, and the cost on the
  un-exported `bb_completed_reservation` row. This path is identical under both buttons,
  so choosing to overwrite never changes what happens to the rest of the series.
* **Locked members → `soallocation::update_price_only()`** (`:283`), a statement naming
  only `cost` and `price_locked`. These are rows the officer never opened in a form, and
  a writer that cannot name a date cannot move one. That is the only part of the
  narrowing enforced by something other than convention. **There is no
  `$skip_validation` flag and there must never be one** — it would become the fifth way
  to write an allocation without validation.

`update_price_only()` therefore carries two of `bo->update()`'s side effects by hand:

| Side effect | Carried? | Why |
|---|---|---|
| `bb_allocation_cost` history row | **yes** | an overwrite of a hand-set price is the one event in this feature that most needs explaining afterwards |
| `bb_completed_reservation.cost` (`export_file_id IS NULL` only) | **yes** | that row is what gets invoiced; leaving it behind bills the old price |
| webhook notification | **no** | it is advisory — fired inside a `try/catch` that swallows its own failures — where the other two are the record |

The overwrite's history rows carry their own literal,
`Pris kopiert fra tildeling i serien (overskrev manuell pris)`, distinct from the plain
cascade's `Pris kopiert fra tildeling i serien`. Six months later the history row is all
that is left of the distinction.

> ⚠️ **The webhook decision is a source-level judgement, not a measurement.** This stack
> has **0 rows** in `bb_webhook_subscriptions` and **0** in `bb_webhook_delivery_log`, so
> neither the notification firing on the unlocked path nor its absence on the locked path
> can be observed here. If webhooks are ever switched on, revisit it.

### ⚠️ Reaching the endpoint without JavaScript

The dialogs are the only thing that posts `cascade_scope`. A POST that carries **no**
scope field — a script-less browser, a direct `curl`, anything written against this
endpoint before the dialogs existed — **cascades to every unlocked member of the group,
with no dialog and no conflict warning** (`:1305`, `:1307`).

That is deliberate: silence keeps the contract that shipped in Phase A rather than
inventing a new one. But it means **the dialogs are a UI affordance, not an enforcement
boundary.** Anything that must not cascade has to say `cascade_scope=this`.

---

## 5. What the officer sees, mechanically

1. Officer changes the price and clicks **Lagre**.
2. The form validator runs first. If the form is invalid, no dialog appears — the
   validator reports the error as usual. (The submit gate is bound on `window.load`, so
   it lands after every `document.ready` handler, and it also asks `$('#form').isValid()`
   directly because the validator binds its own handler only once its modules finish
   loading.)
3. The gate compares the cost field to `cost_orig` **numerically**. With articles on,
   `purchase_order_edit.js` normalises the field to `"500.00"` while the hidden
   `cost_orig` still holds `"500"` — the same price, different text. A string comparison
   would put a dialog in front of every single save.
4. If the price moved, the browser fetches `cascade_preview`. If the allocation is not
   in a group (`grouped: false`), or the fetch fails, the form is submitted exactly as
   it was submitted before the dialogs existed.
5. Otherwise: scope dialog → maybe conflict dialog → hidden fields appended → native
   `form.submit()`.
6. The server ignores all of that except the two field values, and makes the
   price-changed decision over again from the stored row.

---

## 6. How to test it by hand

### Before you start — five traps

1. **`?n=…` is a static cache-buster.** After editing `allocation.js`, a browser that
   already loaded the page serves the **old file** and none of this works. Hard-reload
   (⌘⇧R) or the dialogs simply will not appear. Confirm with
   `typeof allocationPriceDialog === 'function'` in the console.
2. **ISO dates fail as a SEASON error.** The date fields want `d/m-Y H:i`
   (`02/03-2027 20:00`). Posting `2027-03-02 20:00:00` is rejected with
   *"Denne interntildelingen …"* — which names the allocation, not the season, and has
   two possible emitters, so the rendered text does not tell you which file refused.
3. **A successful save is HTTP 302, not 200.** A 200 means the form came back with
   errors. If you are driving this with `curl`, that is a free failure arm on every POST.
4. **The "Artikler" table is not the purchase-order lines.** It is a picker. What is
   posted is `selected_articles[]` in the form
   `<mapping_id>_<quantity>_<tax_code>_<ex_tax_price>_<parent_mapping_id>` — the trailing
   separator matters.
5. **The cost field can reset to `0.00`.** With articles on and no article lines, the
   posted cost is overwritten with zero, with no history row and no cascade. That is a
   pre-existing path upstream of everything described here (`#15843`).

### Set-up

You need a recurrence group with at least three members and the one you edit **in the
middle**, so the `future` boundary has occurrences on both sides. `allocation_group_id`
is only minted by the recurring wizard; for testing, any set of allocations sharing a
group id will do.

```sql
SELECT id, from_, cost, price_locked
FROM bb_allocation WHERE allocation_group_id = <g> ORDER BY from_;
```

Keep that query open. **Run it before and after every single step, with no `LIMIT`.**

---

### T1 · A bare save marks nothing (negative)

Open the edit form. Change **nothing**. Click Lagre.

**Expect:** straight to the show page, **no dialog**. `price_locked` unchanged on every
row, no new `bb_allocation_cost` row anywhere.

*If a dialog appears here, the numeric comparison in step 3 of §5 has regressed.*

---

### T2 · Scope "Oppdater dette" — nothing else moves (negative, with its control)

Change the price. Click Lagre → **Oppdater dette**.

**Expect:** the source takes the new price and `price_locked` goes to 1. **Every other
row in the group is byte-identical** — same cost, same flag, and no new history row.

⚠️ *This is a zero, so it needs T3 to mean anything.* T3 is the same form, the same
click, the same fixture, differing only in the answer — and it does move members. Run
them as a pair or T2 proves nothing.

---

### T3 · Scope "Oppdater fremtidige" — the boundary, both sides

From the **middle** occurrence, change the price. Lagre → **Oppdater fremtidige**.

**Expect:**

* every member with `from_ >= source.from_` takes the new price and gains a history row
  reading `Pris kopiert fra tildeling i serien`;
* **the members that start earlier are untouched** — no cost change, no history row —
  *including unlocked ones*. That is what proves the date bound rather than the lock;
* the source is `price_locked = 1`; **every member it moved is `price_locked = 0`**.

---

### T4 · Scope "Oppdater alle" reaches the earlier ones

Same source, Lagre → **Oppdater alle**.

**Expect:** the earlier member that T3 left alone now moves too. This is T3's control.

---

### T5 · The conflict dialog fires only when there is a conflict

* **Negative arm** — pick a scope whose members are all `price_locked = 0`. Choosing it
  goes **straight to the save**; no second dialog.
* **Positive arm** — lock one member (open it, change its price, answer *Oppdater
  dette*), then choose a scope that contains it. The conflict dialog appears.
* **Check the numbers**, don't just check that it appeared:

```sql
SELECT count(*) AS total, COALESCE(SUM(price_locked),0) AS locked
FROM bb_allocation
WHERE allocation_group_id = <g> AND id <> <source> AND active = 1
  /* AND from_ >= '<source.from_>'   -- add for the "future" scope */;
```

The dialog's *"%1 av %2"* must equal `locked` and `total` from that query exactly.

---

### T6 · "Behold de manuelle" skips the locked member entirely

With a locked member in scope, Lagre → Oppdater alle → **Behold de manuelle**.

**Expect on the locked member:** cost unchanged, `price_locked` still 1, **and no new
`bb_allocation_cost` row**.

> 🔎 A sharper check, and a genuinely useful thing to know: `bo->update()` rewrites an
> allocation's cost history by **deleting every `bb_allocation_cost` row and re-inserting
> them**, so a member that was written has entirely **new row ids** while its `time`
> values are preserved. A member that was *skipped* keeps its original ids. Compare
> `SELECT id FROM bb_allocation_cost WHERE allocation_id = <locked>` before and after: if
> the ids are unchanged, the row was never passed to the writer at all — which is a
> stronger statement than "nothing changed".

---

### T7 · "Overskriv alle" overwrites, audits, and unlocks

Same set-up, but choose **Overskriv alle**.

**Expect on each previously-locked member:**

* cost = the new price;
* **`price_locked` cleared to 0**;
* a new history row reading
  `Pris kopiert fra tildeling i serien (overskrev manuell pris)`;
* its older history rows **still there**.

---

### T8 · The source is not unlocked (one query, both facts)

```sql
SELECT id, cost, price_locked,
       CASE WHEN id = <source> THEN 'SOURCE' ELSE 'member' END AS role
FROM bb_allocation WHERE allocation_group_id = <g> ORDER BY from_;
```

**Expect:** `SOURCE` is `price_locked = 1` while every overwritten member is `0`.
Overwriting *others* does not unmark the source — it is still the row whose price a human
deliberately chose this time round.

---

### T9 · The clearing sticks — and its control

This is an *absence*, so it carries its positive control in the same run.

1. Lock **two** members in the future half, and **one** in the past half.
2. From the middle occurrence, Lagre → **Oppdater fremtidige**. The dialog reads
   **"2 av 2"**.
3. Choose **Overskriv alle**. Both future members take the price and drop to
   `price_locked = 0`.
4. Re-open `cascade_preview` (or just save again): the `future` scope now reports
   `locked: 0` and **the conflict dialog does not fire**.
5. **Control, same response:** the `all` scope still reports `locked: 1` — the member
   you locked in the past half — and the dialog **does** still fire.

Step 4 on its own is a zero from a dialog that might simply be broken. Step 5 makes it a
result.

---

### T10 · Avbryt writes nothing

Change the price, Lagre, and press **Avbryt** — once at the scope dialog, once at the
conflict dialog.

**Expect both times:** the dialog closes, you stay on the form, and **nothing at all is
written** — not the members, and not the source. Same costs, same flags, same history
row counts.

---

### T11 · `cost_orig` cannot be talked into or out of a cascade

Only reachable with `curl` (the field is hidden). Post a **real** price change with
`cost_orig` forged to the *new* value — the client claiming nothing changed. Then post
**no** price change with `cost_orig` forged to something else — the client claiming it
did.

**Expect:** the first marks and cascades; the second does neither. The decision is made
against the stored row, and `cost_orig` never reaches the server's reasoning.

---

## 7. What is not covered

Everything in this section is **written and unexercised, or out of reach on this stack.**
None of it is a claim about behaviour.

* **`PUT /booking/allocations/{id}`** — changes the price and records nothing: no history
  row, no `price_locked`, no cascade, and a price set that way will be silently
  overwritten by the next "Oppdater alle". Demonstrated (`#15842`), unowned, and **not**
  in #982's spec.
* **Articles OFF.** `activate_application_articles` is a single global `phpgw_config`
  row with no per-instance override, and changing it is forbidden. The articles-OFF
  branch of `$effective_cost` (`:1268`) is written and lints; **nothing is claimed about
  its behaviour.**
* **The webhook notification.** 0 subscriptions, 0 delivery-log rows on this stack — the
  side effect the override writer drops is unobservable here.
* **The recurring-wizard mint path.** `next_allocation_group_id()` is called at
  `class.uiallocation.inc.php:806`, inside the wizard's recurring branch. It has not been
  driven end-to-end; the groups used for testing had their `allocation_group_id` set
  directly.
* **Concurrent edits.** The cascade runs **outside any transaction**. Two officers
  editing two members of one group at the same time, or a mid-loop `bo->update()` failure,
  will split the series — the failure aborts before the redirect, leaving some members
  moved and some not. Not exercised, not guarded.
* **`get_unlocked_group_member_ids` filters `active = 1`.** A deactivated member of a
  series is silently outside every scope, and is not counted in the dialog either. That
  is probably right; it has never been decided.
* **Inactive/soft-deleted members and `bb_booking` children** are not considered at all.
* **The officer's `cost_comment` is discarded** when articles are on and the purchase
  order derived a different sum: `$purchase_order_logged` is already true, so the history
  row reads `lang('cost is set')` — *"Ny pris er angitt"* — instead of what he typed.
  Pre-existing, upstream of this feature.
* **These dialogs are built for the legacy XSL form and will be rewritten when that form
  is replaced** (spec: `forum/pe-queue/15852`). That cost was accepted deliberately: a
  working feature beats a half-feature held behind a rewrite.

---

## 8. Two things that bit during the build

Both cost real time and neither is obvious from the code.

**`lang('common.<key>')` does not reach `common`.** `CommonFunctions.php` documents
`common.` as *"restricts to common translations only"*, and
`Translation::translate()` accepts an `$only_common` flag — but the guard that decides
whether to run the lookup query is `(… && (!$only_common && …))`, so **with
`$only_common` set the SQL never runs at all.** The call can therefore only return
whatever is already in the per-request static cache: `!key` on a cold cache, and the
**current module's** value once anything has warmed it. Measured on
`lang('common.cancel')` from the `booking` module: `!cancel` on the first request of a
session, `Tilbake` (booking's own value) on every one after — and never `Avbryt`, which
is what `common` actually holds. **Give a key its own entry in your module.**

**`php test_lang_files.php --add-translation` handles commas fine.** The `--langs=` value
is split on `,(?=[a-z]{2}:)`, not on bare commas, so a translation containing a comma is
safe. (Two `--langs=` parsers exist in that file; the one at the top of `$argv` parsing is
dead in add-translation mode.) A translation whose text contains something like
`, no: …` would still split — but ordinary prose will not.
