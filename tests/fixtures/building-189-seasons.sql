-- Local repro fixture for building 189 "Sesongbygg" with two overlapping seasons
-- (different resources / different opening hours) used to reproduce and verify the
-- "Outside opening hours" calendar drag bug. Ported from test.aktiv-kommune.no.
BEGIN;

INSERT INTO bb_building
  (id, name, homepage, phone, email, active, street, zip_code, city, district,
   location_code, deactivate_calendar, deactivate_application, deactivate_sendmessage,
   extra_kalendar, activity_id, opening_hours, description_json, short_description)
VALUES
  (189, 'Sesongbygg', '0', '', '', 1, 'Nedre sesong', '', '', 'Bergen',
   '', 0, 0, 0, 0, 2, '', '{"en": "", "nn": "", "no": ""}', '{"en": "", "nn": "", "no": ""}');

INSERT INTO bb_resource
  (id, name, activity_id, active, sort, organizations_ids, json_representation, rescategory_id,
   opening_hours, contact_info, direct_booking, booking_day_default_lenght, booking_dow_default_start,
   booking_time_default_start, booking_time_default_end, simple_booking, simple_booking_start_date,
   booking_month_horizon, booking_day_horizon, capacity, deactivate_calendar, deactivate_application,
   booking_time_minutes, booking_limit_number, booking_limit_number_horizont, hidden_in_frontend,
   activate_prepayment, booking_buffer_deadline, deny_application_if_booked,
   cancellation_deadline_value, cancellation_deadline_unit, description_json, short_description)
VALUES
  (946, 'Grupperom tidsslot', 2, 1, 0, '', '{"2390": []}', 7,
   '', '', 1781528400, -1, -1,
   10, 14, 1, 1781527260,
   0, 1, 0, 0, 0,
   0, 0, 0, 0,
   0, 0, 0,
   0, 'hours', '{"en": "", "nn": "", "no": ""}', '{"en": "", "nn": "", "no": ""}'),
  (947, 'Lokalet på kveldstid', 2, 1, 0, '', NULL, 7,
   '', '', NULL, 0, 0,
   0, 0, NULL, NULL,
   0, 0, 0, 0, 0,
   0, 0, 0, 0,
   0, 0, 0,
   0, 'hours', '{"en": "", "nn": "", "no": ""}', '{"en": "", "nn": "", "no": ""}');

INSERT INTO bb_building_resource (building_id, resource_id) VALUES
  (189, 946),
  (189, 947);

INSERT INTO bb_season (id, building_id, name, status, from_, to_, active, officer_id) VALUES
  (1118, 189, 'Sesongbygg tidsslot',        'PUBLISHED', '2026-06-15', '2026-08-31', 1, 7),
  (1119, 189, 'Sesongbygg lokale kveldstid', 'PUBLISHED', '2026-06-15', '2026-07-31', 1, 7);

INSERT INTO bb_season_resource (season_id, resource_id) VALUES
  (1118, 946),
  (1119, 947);

-- Season 1118: weekdays Mon-Fri 09:00-14:30 (resource 946)
INSERT INTO bb_season_boundary (season_id, wday, from_, to_) VALUES
  (1118, 1, '09:00:00', '14:30:00'),
  (1118, 2, '09:00:00', '14:30:00'),
  (1118, 3, '09:00:00', '14:30:00'),
  (1118, 4, '09:00:00', '14:30:00'),
  (1118, 5, '09:00:00', '14:30:00');

-- Season 1119: weekdays Mon-Fri 14:30-20:00 (resource 947)
INSERT INTO bb_season_boundary (season_id, wday, from_, to_) VALUES
  (1119, 1, '14:30:00', '20:00:00'),
  (1119, 2, '14:30:00', '20:00:00'),
  (1119, 3, '14:30:00', '20:00:00'),
  (1119, 4, '14:30:00', '20:00:00'),
  (1119, 5, '14:30:00', '20:00:00');

-- Keep sequences ahead of the explicit IDs we inserted
SELECT setval('seq_bb_building', GREATEST((SELECT max(id) FROM bb_building), 189));
SELECT setval('seq_bb_resource', GREATEST((SELECT max(id) FROM bb_resource), 947));
SELECT setval('seq_bb_season',   GREATEST((SELECT max(id) FROM bb_season), 1119));

COMMIT;
