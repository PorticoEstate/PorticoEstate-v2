/**
 * The IANA zone every booking instant is DISPLAYED and DERIVED in — every DateTime
 * built from a server timestamp, whether rendered to the eye or turned into a URL
 * segment, cache key, or day-boundary comparison — regardless of the viewer's own
 * device/browser zone. A municipal booking system must show the same wall-clock, and
 * resolve the same calendar day, for a citizen checking from abroad as for the
 * officer in the building.
 *
 * A CONSTANT, not configuration: neither `phpgw_config` (236 rows / 9 apps — no
 * timezone-like key exists) nor the `/api/server-settings` payload (IServerSettings,
 * booking_config, bookingfrontend_config) carries a timezone value, and PHP's own
 * `date.timezone` ini is UTC. 'Europe/Oslo' is instead hardcoded independently in 30+
 * backend call sites (SerializableTrait's @Timestamp default, FreeTimeService,
 * HospitalityOrderController, the WebSocket service, ...) plus this client.
 *
 * COST OF CHANGING THIS: editing this one file does not change the venue's zone — it
 * only desyncs this client from those 30+ backend sites, which stay hardcoded to
 * 'Europe/Oslo'. There is no single place that currently makes this configurable;
 * changing it correctly means moving all of them together, not swapping this literal.
 */
export const VENUE_TIMEZONE = 'Europe/Oslo';
