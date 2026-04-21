# TODO

## Resource: cancellation_deadline enforcement

A `cancellation_deadline_value` (int, hours/days/weeks) + `cancellation_deadline_unit` field pair has been added to `bb_resource` (schema migration `0.2.128 → 0.2.129`). The field is currently **stored and displayed only** — no enforcement.

### What still needs doing

- **booking module (admin):** When an admin cancels a booking/allocation close to the event, should we warn/block if inside the window? Probably warn-only for admins.
- **bookingfrontend (Next.js client):**
  - Surface the deadline on the booking detail / cancel flow (show "Can be cancelled until {date}" and disable the cancel button past the deadline).
  - Return a clear error from the cancel endpoint if the deadline has passed.
- **bookingfrontend (PHP side):** Enforce server-side in whatever controller handles public cancellation. Client-side checks are a UX nicety; the authoritative check must live in PHP.
- **Unit handling:** Centralise the "value + unit → seconds" conversion so every caller agrees (see how `order_by_time_value` / `order_by_time_unit` is handled in hospitality for reference).
- **Multi-resource bookings:** If a booking involves several resources with different cancellation deadlines, decide the policy — strictest wins is the obvious default.
- **Notifications:** Consider whether approaching cancellation deadlines should trigger a reminder (probably out of scope, but worth flagging).
- **Tests:** Add tests for boundary conditions (exactly at the deadline, unit conversions, null/0 = no deadline).
