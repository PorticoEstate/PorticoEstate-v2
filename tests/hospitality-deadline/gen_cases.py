#!/usr/bin/env python3
"""
Generates cases.json for the catering deadline parity harness (GH #1423).

WHY A THIRD IMPLEMENTATION: the point of the harness is that the PHP calculator/guard and the
TypeScript mirror agree. If the expected values were produced by either of them the test could
only ever prove a program equals itself. This oracle is a separate implementation in a separate
runtime with a separate DST engine (zoneinfo), and it is checked against LITERAL values worked
out by hand (HAND_* below): the generator refuses to write cases.json if the oracle disagrees
with any of them.

The rule (see HospitalityOrderGuard.php / hospitality-deadline.ts):
  deadline(S, value, unit, open_days)
    value <= 0 / None, unit None or not hours|days|weeks   -> None
    weeks                                                  -> 7*value calendar days, wall clock,
                                                              open_days ignored
    all 7 days open, hours                                 -> elapsed hours
    all 7 days open, days                                  -> calendar days, wall clock
    some days closed, days                                 -> count BACKWARDS in open days only
    some days closed, hours                                -> hour blocks, closed-day hours skipped
  open  <=>  now <= deadline          (exactly AT the deadline is still open)
  add    open while now <= C(S')
  change open while now <= C(S) and now <= X(S); moving S also needs now <= C(S') and now <= S'
  cancel open while now <= X(S)
  every action also needs now <= S

Run:  python3 tests/hospitality-deadline/gen_cases.py
"""
import json
import os
import sys
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

OSLO = ZoneInfo("Europe/Oslo")
UTC = timezone.utc


def local(y, mo, d, h=12, mi=0, s=0):
    return datetime(y, mo, d, h, mi, s, tzinfo=OSLO)


def wall_sub_days(dt, n):
    return (dt.replace(tzinfo=None) - timedelta(days=n)).replace(tzinfo=OSLO)


def elapsed_sub_hours(dt, n):
    return (dt.astimezone(UTC) - timedelta(hours=n)).astimezone(OSLO)


def deadline(event, value, unit, mask):
    if value is None or value <= 0 or unit is None:
        return None
    u = unit.lower()
    if u not in ("hours", "days", "weeks"):
        return None
    mask &= 127
    if mask == 0:
        mask = 127
    if u == "weeks":
        return wall_sub_days(event, 7 * value)
    if mask == 127:
        return elapsed_sub_hours(event, value) if u == "hours" else wall_sub_days(event, value)

    def is_open(dt):
        return bool(mask & (1 << (dt.astimezone(OSLO).isoweekday() - 1)))

    if u == "days":
        cur = wall_sub_days(event, 1)
        counted = 0
        for _ in range(366 * 5):
            if is_open(cur):
                counted += 1
                if counted >= value:
                    return cur
            cur = wall_sub_days(cur, 1)
        return cur
    cur = event
    remaining = value
    for _ in range(24 * 366 * 5):
        if remaining <= 0:
            break
        prev = elapsed_sub_hours(cur, 1)
        if is_open(prev):
            remaining -= 1
        cur = prev
    return cur


def window(action, anchor, moved_to, ob, cb, mask, now):
    cutoff = deadline(anchor, ob[0], ob[1], mask)
    cancel_by = deadline(anchor, cb[0], cb[1], mask)
    if action == "add":
        closers = [(cutoff, "order_cutoff_passed")]
    elif action == "change":
        closers = [(cancel_by, "cancellation_deadline_passed"), (cutoff, "order_cutoff_passed")]
    else:
        closers = [(cancel_by, "cancellation_deadline_passed")]
    closers.append((anchor, "serving_time_passed"))

    until = None
    closed = None
    for inst, reason in closers:
        if inst is None:
            continue
        if until is None or inst < until:
            until = inst
        if now > inst and (closed is None or inst < closed[0]):
            closed = (inst, reason)
    if closed:
        return {"allowed": False, "reason": closed[1], "until": closed[0]}
    if action == "change" and moved_to is not None:
        new_cutoff = deadline(moved_to, ob[0], ob[1], mask)
        if new_cutoff is not None and now > new_cutoff:
            return {"allowed": False, "reason": "order_cutoff_passed", "until": new_cutoff}
        if now > moved_to:
            return {"allowed": False, "reason": "serving_time_passed", "until": moved_to}
    return {"allowed": True, "reason": None, "until": until}


def iso(dt):
    return None if dt is None else dt.isoformat()


def epoch(dt):
    return None if dt is None else int(dt.timestamp())


# --- hand-worked literals: (name, event, value, unit, mask, expected ISO or None) -----------------
# Worked out on paper from the calendar (2026-11-02 is a Monday; Europe/Oslo leaves summer time on
# 2026-10-25 and enters it on 2026-03-29). The oracle must reproduce every one or nothing is written.
HAND_DEADLINES = [
    ("H1 8 hours before Monday noon", local(2026, 11, 2), 8, "hours", 127, "2026-11-02T04:00:00+01:00"),
    ("H2 2 calendar days before Monday noon", local(2026, 11, 2), 2, "days", 127, "2026-10-31T12:00:00+01:00"),
    ("H3 2 working days (Mon-Fri) before Monday noon skips the weekend", local(2026, 11, 2), 2, "days", 31, "2026-10-29T12:00:00+01:00"),
    ("H4 1 working day before Monday noon is the Friday", local(2026, 11, 2), 1, "days", 31, "2026-10-30T12:00:00+01:00"),
    ("H5 3 days, Saturday closed only, before Monday", local(2026, 11, 2), 3, "days", 95, "2026-10-29T12:00:00+01:00"),
    ("H6 1 week ignores the open-days mask", local(2026, 11, 2), 1, "weeks", 31, "2026-10-26T12:00:00+01:00"),
    ("H7 1 week across the DST end keeps the wall clock", local(2026, 10, 26), 1, "weeks", 127, "2026-10-19T12:00:00+02:00"),
    ("H8 2 days across the DST end keeps the wall clock", local(2026, 10, 26), 2, "days", 127, "2026-10-24T12:00:00+02:00"),
    ("H9a 24 hours across the DST end is elapsed time", local(2026, 10, 26), 24, "hours", 127, "2026-10-25T12:00:00+01:00"),
    ("H9b 36 hours across the DST end is elapsed time", local(2026, 10, 26), 36, "hours", 127, "2026-10-25T01:00:00+02:00"),
    ("H10a 48 hours across the DST start is elapsed time", local(2026, 3, 31), 48, "hours", 127, "2026-03-29T12:00:00+02:00"),
    ("H10b 60 hours across the DST start is elapsed time", local(2026, 3, 31), 60, "hours", 127, "2026-03-28T23:00:00+01:00"),
    ("H11 2 days across the DST start keeps the wall clock", local(2026, 3, 30), 2, "days", 127, "2026-03-28T12:00:00+01:00"),
    ("H12 8 working hours before Monday 06:00 skip the closed weekend", local(2026, 11, 2, 6), 8, "hours", 31, "2026-10-30T22:00:00+01:00"),
    ("H13 value 0 is no deadline", local(2026, 11, 2), 0, "hours", 127, None),
    ("H14 negative value is no deadline", local(2026, 11, 2), -1, "days", 127, None),
    ("H15 unknown unit is no deadline", local(2026, 11, 2), 2, "minutes", 127, None),
    ("H16 upper-case unit is read case-insensitively", local(2026, 11, 2), 2, "DAYS", 127, "2026-10-31T12:00:00+01:00"),
    ("H17 an empty mask means every day is open", local(2026, 11, 2), 2, "days", 0, "2026-10-31T12:00:00+01:00"),
    ("H18 bits above Sunday are ignored (255 == 127)", local(2026, 11, 2), 2, "days", 255, "2026-10-31T12:00:00+01:00"),
]

HAND_NULL_VALUE = ("H19 null value is no deadline", local(2026, 11, 2), None, "days", 127, None)
HAND_NULL_UNIT = ("H20 null unit is no deadline", local(2026, 11, 2), 2, None, 127, None)


def build_deadline_cases():
    cases = []
    for name, ev, value, unit, mask, expected in HAND_DEADLINES + [HAND_NULL_VALUE, HAND_NULL_UNIT]:
        got = iso(deadline(ev, value, unit, mask))
        want = None if expected is None else datetime.fromisoformat(expected).astimezone(OSLO).isoformat()
        if got != want:
            sys.exit(f"ORACLE DISAGREES WITH HAND LITERAL {name!r}: oracle={got} hand={want}")
        cases.append({"name": name, "event": iso(ev), "value": value, "unit": unit,
                      "open_days": mask, "expected": epoch(deadline(ev, value, unit, mask)),
                      "expected_iso": got, "kind": "hand"})

    # The grid: every shape across weekend, Monday morning, both DST changes, year end.
    events = [
        local(2026, 11, 2, 12), local(2026, 11, 3, 9, 30), local(2026, 11, 6, 8),
        local(2026, 11, 7, 10), local(2026, 11, 8, 18),
        local(2026, 3, 30, 12), local(2026, 3, 31, 12), local(2026, 3, 29, 12),
        local(2026, 10, 26, 12), local(2026, 10, 27, 9), local(2026, 10, 25, 14),
        local(2026, 12, 31, 20), local(2027, 1, 1, 9), local(2027, 1, 4, 8),
    ]
    masks = [127, 31, 95, 1, 96, 0, 255]
    spec = {"hours": [1, 8, 24, 36, 48], "days": [1, 2, 3, 5], "weeks": [1, 2]}
    for ev in events:
        for mask in masks:
            for unit, values in spec.items():
                for value in values:
                    d = deadline(ev, value, unit, mask)
                    cases.append({"name": f"g {ev.strftime('%Y-%m-%d %H:%M')} -{value}{unit[0]} m{mask}",
                                  "event": iso(ev), "value": value, "unit": unit, "open_days": mask,
                                  "expected": epoch(d), "kind": "grid"})
    return cases


def action_case(name, action, anchor, ob, cb, mask, now, moved_to=None, kind="hand", expected_hand=None):
    w = window(action, anchor, moved_to, ob, cb, mask, now)
    got = {"allowed": w["allowed"], "reason": w["reason"], "until": epoch(w["until"])}
    if expected_hand is not None:
        want = {"allowed": expected_hand[0], "reason": expected_hand[1],
                "until": None if expected_hand[2] is None else int(datetime.fromisoformat(expected_hand[2]).timestamp())}
        if got != want:
            sys.exit(f"ORACLE DISAGREES WITH HAND ACTION {name!r}: oracle={got} hand={want}")
    return {"name": name, "action": action, "anchor": iso(anchor), "moved_to": iso(moved_to),
            "order_by": {"value": ob[0], "unit": ob[1]}, "cancel_by": {"value": cb[0], "unit": cb[1]},
            "open_days": mask, "now": iso(now), "expected": got, "kind": kind}


def build_action_cases():
    out = []
    S = local(2026, 11, 10)          # a Tuesday, noon
    nov8 = local(2026, 11, 8)        # S - 2 days
    nov9 = local(2026, 11, 9)        # S - 24 h
    s = timedelta(seconds=1)
    # hospitality-4 shape: order cutoff 8 hours, kitchen cancellation deadline 2 days
    ob, cb = (8, "hours"), (2, "days")
    X = "2026-11-08T12:00:00+01:00"
    C = "2026-11-10T04:00:00+01:00"
    out += [
        action_case("A1 change one second before X is open", "change", S, ob, cb, 127, nov8 - s, expected_hand=(True, None, X)),
        action_case("A1 change exactly AT X is open", "change", S, ob, cb, 127, nov8, expected_hand=(True, None, X)),
        action_case("A1 change one second after X is locked by the cancellation deadline", "change", S, ob, cb, 127, nov8 + s, expected_hand=(False, "cancellation_deadline_passed", X)),
        action_case("A2 cancel exactly AT X is open", "cancel", S, ob, cb, 127, nov8, expected_hand=(True, None, X)),
        action_case("A2 cancel one second after X is locked", "cancel", S, ob, cb, 127, nov8 + s, expected_hand=(False, "cancellation_deadline_passed", X)),
        action_case("A3 add exactly AT C is open (X is irrelevant to add)", "add", S, ob, cb, 127, local(2026, 11, 10, 4), expected_hand=(True, None, C)),
        action_case("A3 add one second after C is locked", "add", S, ob, cb, 127, local(2026, 11, 10, 4) + s, expected_hand=(False, "order_cutoff_passed", C)),
        action_case("A3 add is still open after X", "add", S, ob, cb, 127, nov9, expected_hand=(True, None, C)),
        action_case("A10 between X and C: change locked, add open", "change", S, ob, cb, 127, nov9, expected_hand=(False, "cancellation_deadline_passed", X)),
    ]
    # X unset (kitchen with no cancellation deadline), C = 24 hours  (hospitality 5/6 shape)
    ob24, cb0 = (24, "hours"), (0, "hours")
    c24 = "2026-11-09T12:00:00+01:00"
    out += [
        action_case("A4 change after C with no X is locked by the order cutoff", "change", S, ob24, cb0, 127, nov9 + s, expected_hand=(False, "order_cutoff_passed", c24)),
        action_case("A4 cancel after C with no X stays open until the serving time", "cancel", S, ob24, cb0, 127, nov9 + s, expected_hand=(True, None, "2026-11-10T12:00:00+01:00")),
        action_case("A4 cancel after the serving time is locked", "cancel", S, ob24, cb0, 127, S + s, expected_hand=(False, "serving_time_passed", "2026-11-10T12:00:00+01:00")),
    ]
    # C unset, X = 1 day
    out += [
        action_case("A5 change with no C is closed by X", "change", S, (0, "hours"), (1, "days"), 127, nov9 + s, expected_hand=(False, "cancellation_deadline_passed", c24)),
        action_case("A5 add with no C is open until the serving time", "add", S, (0, "hours"), (1, "days"), 127, S, expected_hand=(True, None, "2026-11-10T12:00:00+01:00")),
    ]
    # X == C: a tie goes to the cancellation deadline
    out += [
        action_case("A6 X equals C: the reason is the cancellation deadline", "change", S, (24, "hours"), (24, "hours"), 127, nov9 + s, expected_hand=(False, "cancellation_deadline_passed", c24)),
    ]
    # neither configured
    none = (0, "hours")
    out += [
        action_case("A7 nothing configured, at the serving time", "change", S, none, none, 127, S, expected_hand=(True, None, "2026-11-10T12:00:00+01:00")),
        action_case("A7 nothing configured, after the serving time", "change", S, none, none, 127, S + s, expected_hand=(False, "serving_time_passed", "2026-11-10T12:00:00+01:00")),
    ]
    # moved serving time
    moved = local(2026, 11, 3)
    out += [
        action_case("A8 moving the order to a slot whose cutoff has passed", "change", S, ob, none, 127, local(2026, 11, 3, 4) + s, moved_to=moved, expected_hand=(False, "order_cutoff_passed", "2026-11-03T04:00:00+01:00")),
        action_case("A8 moving to a slot whose cutoff is still open", "change", S, ob, none, 127, local(2026, 11, 3, 4), moved_to=moved, expected_hand=(True, None, C)),
        action_case("A9 moving to a slot already in the past, nothing configured", "change", S, none, none, 127, moved + s, moved_to=moved, expected_hand=(False, "serving_time_passed", "2026-11-03T12:00:00+01:00")),
        # stored slot closed by X although the new slot is far in the future: still refused
        action_case("A11 stored slot locked by X, new slot open: refused", "change", S, ob, cb, 127, nov8 + s, moved_to=local(2026, 11, 20), expected_hand=(False, "cancellation_deadline_passed", X)),
    ]
    # working-days mode: 2 working days (Mon-Fri) cancel deadline before a Monday
    mon = local(2026, 11, 2)
    out += [
        action_case("A12 cancel, 2 working days, open on Thursday noon", "cancel", mon, none, (2, "days"), 31, local(2026, 10, 29), expected_hand=(True, None, "2026-10-29T12:00:00+01:00")),
        action_case("A12 cancel, 2 working days, locked on Thursday noon + 1 s", "cancel", mon, none, (2, "days"), 31, local(2026, 10, 29) + s, expected_hand=(False, "cancellation_deadline_passed", "2026-10-29T12:00:00+01:00")),
    ]

    # Grid: every (action, config shape) at deadline-1s / deadline / deadline+1s of each closer.
    shapes = [
        ("hosp4", ob, cb), ("hosp5", ob24, cb0), ("onlyX", none, (1, "days")), ("equal", (24, "hours"), (24, "hours")),
        ("weeks", (1, "days"), (1, "weeks")), ("none", none, none),
    ]
    anchors = [local(2026, 11, 10), local(2026, 11, 2), local(2026, 3, 30), local(2026, 10, 26)]
    for anchor in anchors:
        for mask in (127, 31):
            for label, o, c in shapes:
                instants = [anchor]
                for cfg in (o, c):
                    d = deadline(anchor, cfg[0], cfg[1], mask)
                    if d is not None:
                        instants.append(d)
                for action in ("add", "change", "cancel"):
                    for inst in instants:
                        for delta in (-s, timedelta(0), s):
                            now = inst + delta
                            out.append(action_case(
                                f"g {action} {label} {anchor.strftime('%Y-%m-%d')} m{mask} {now.strftime('%m-%d %H:%M:%S%z')}",
                                action, anchor, o, c, mask, now, kind="grid"))
    return out


def build_lock_cases():
    """Truth table for the PHP-only locks (the client never evaluates these: the server returns them)."""
    S = local(2026, 11, 10)
    far = local(2026, 11, 1)
    base = {"anchor": iso(S), "order_by": {"value": 8, "unit": "hours"}, "cancel_by": {"value": 2, "unit": "days"},
            "open_days": 127, "now": iso(far), "moved_to": None}

    def lock(name, action, expected, **over):
        c = dict(base)
        c.update({"name": name, "action": action, "application_status": "ACCEPTED", "order_status": "pending",
                  "billed": False, "paid_at_checkout": False})
        c.update(over)
        c["expected"] = {"allowed": expected[0], "reason": expected[1]}
        return c

    return [
        lock("L0 nothing locks it", "change", (True, None)),
        lock("L1 REJECTED application", "change", (False, "application_closed"), application_status="REJECTED"),
        lock("L1 CANCELLED application, cancel", "cancel", (False, "application_closed"), application_status="CANCELLED"),
        lock("L1 REJECTED application, add", "add", (False, "application_closed"), application_status="REJECTED", order_status=None),
        lock("L1 unknown status is closed (whitelist)", "change", (False, "application_closed"), application_status="WHATEVER"),
        lock("L2 order already cancelled", "change", (False, "order_status"), order_status="cancelled"),
        lock("L2 order delivered, cancel", "cancel", (False, "order_status"), order_status="delivered"),
        lock("L2 order CONFIRMED may still be changed (operator D4 = allow)", "change", (True, None), order_status="confirmed"),
        lock("L3 billed order", "change", (False, "order_billed"), billed=True),
        lock("L3 billed order, cancel", "cancel", (False, "order_billed"), billed=True),
        lock("L4 paid at checkout", "cancel", (False, "order_paid_at_checkout"), paid_at_checkout=True),
        lock("L5 no anchor at all fails closed", "change", (False, "no_deadline_basis"), anchor=None),
        lock("L6 draft cancel is always allowed even after the serving time", "cancel", (True, None),
             application_status="NEWPARTIAL1", now=iso(S + timedelta(days=3))),
        lock("L7 draft change ignores the cancellation deadline", "change", (True, None),
             application_status="NEWPARTIAL1", now=iso(local(2026, 11, 9))),
        lock("L8 draft change still obeys the order cutoff of the serving time", "change", (False, "order_cutoff_passed"),
             application_status="NEWPARTIAL1", now=iso(local(2026, 11, 10, 4) + timedelta(seconds=1))),
        lock("L9 draft change to a new slot is judged on the new slot", "change", (True, None),
             application_status="NEWPARTIAL1", now=iso(local(2026, 11, 9)), moved_to=iso(local(2026, 11, 20))),
        lock("L10 billed does not block ADD", "add", (True, None), billed=True, order_status=None),
        lock("L11 PENDING application is open", "change", (True, None), application_status="PENDING"),
        lock("L12 NEW application is open", "change", (True, None), application_status="NEW"),
    ]


def main():
    out = {
        "meta": {
            "timezone": "Europe/Oslo",
            "generator": "tests/hospitality-deadline/gen_cases.py",
            "note": "expected values come from an independent Python oracle (zoneinfo), checked against "
                    "hand-worked literals; epoch seconds are compared, never formatted strings",
        },
        "deadlines": build_deadline_cases(),
        "actions": build_action_cases(),
        "locks": build_lock_cases(),
    }
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "cases.json")
    # One compact case per line: small, and a changed case shows up as a one-line diff.
    def dump(obj):
        return json.dumps(obj, ensure_ascii=False, separators=(",", ":"))
    with open(path, "w") as fh:
        fh.write("{\n")
        fh.write('"meta":' + dump(out["meta"]) + ",\n")
        sections = ["deadlines", "actions", "locks"]
        for i, key in enumerate(sections):
            fh.write(f'"{key}":[\n')
            fh.write(",\n".join(dump(c) for c in out[key]))
            fh.write("\n]" + ("," if i < len(sections) - 1 else "") + "\n")
        fh.write("}\n")
    print(f"wrote {path}: {len(out['deadlines'])} deadline cases, {len(out['actions'])} action cases, {len(out['locks'])} lock cases")


if __name__ == "__main__":
    main()
