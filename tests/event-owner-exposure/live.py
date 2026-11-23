#!/usr/bin/env python3
"""
Live arms for the owner-match fix (GH #1492, task 48050; operator ruling forum/pe-general/48190), against a RUNNING
stack: what GET /bookingfrontend/buildings/{id}/schedule (the Next client's calendar source) gives a viewer with no
session, a logged-in citizen who owns the fixture (by ssn, then by an organisation they are delegate of), and a
logged-in citizen who does not. run.php is the hermetic harness; this asks the served code the same questions.

⚠️ It WRITES the invoice fields and is_public of ONE event, the fixture you name (your own test event on a development
database), and logs in as the local citizen («Privatperson», the development login with no ID-porten). Nothing else
is written; the fixture is put back to the state it had at the end. The owner's ssn and organisation number come from
that citizen's own /bookingfrontend/user response and reach the database on psql's stdin: they are never printed.

Usage:
  PB_PSQL_CONTAINER=... PB_PSQL_DB=... python3 -I tests/event-owner-exposure/live.py <fixture event id>
Optional: PB_BASE (default https://pe-api.test), PB_PSQL_USER (default postgres), PB_PLACEHOLDER (default
'Privat arrangement', the nb placeholder of a hidden name).

Arms (the names of run.php where they are the same question):
  C   the owner arms are GREEN on whatever is served (an owner who cannot see their own event voids the run)
  N1  owner by organisation number: name, secret, contact, organizer      N2  owner by ssn: the same, and customer_ssn
  N4  a public event with a number: its name to no session, no secret      N12 the legacy info page: public 1 / private 0
  F1  no session, private, organisation-number type + '': no secret, contact, organizer, description, customer_ssn;
      the placeholder                                                     F3  the same for an ssn type + ''
  F4  the logged-in citizen, not the owner, on F1's row: the same          F6  a PUBLIC event stored as F1: the name, no secret
  N13 owners keep customer_identifier_type (+ customer_organization_number by org nr)
  F11 no session gets neither of those two, on a public and a private event with a number
Exit: 0 all GREEN, 1 any RED, 2 a control failed (the run proves nothing).
"""
import http.cookiejar
import json
import os
import ssl
import subprocess
import sys
import urllib.parse
import urllib.request

BASE = os.environ.get('PB_BASE', 'https://pe-api.test')
PLACEHOLDER = os.environ.get('PB_PLACEHOLDER', 'Privat arrangement')
GATED = ['secret', 'contact_name', 'contact_email', 'contact_phone', 'organizer', 'description', 'customer_ssn']
# Owner-only since their docblocks were closed with «)»; reported apart, so the rows above read the same as in run.php.
IDS = ['customer_identifier_type', 'customer_organization_number']

CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode = ssl.CERT_NONE


def need(name):
	v = os.environ.get(name)
	if not v:
		sys.exit(f'{name} is not set (see the usage at the top of this file)')
	return v


def psql(sql):
	"""SQL on stdin, so a value in it is never in a process argument or in the output."""
	out = subprocess.run(['docker', 'exec', '-i', need('PB_PSQL_CONTAINER'), 'psql', '-U', os.environ.get('PB_PSQL_USER', 'postgres'),
						  '-d', need('PB_PSQL_DB'), '-AtF', '|', '-v', 'ON_ERROR_STOP=1'], input=sql, capture_output=True, text=True, check=True)
	return out.stdout.strip()


def lit(value):
	return 'NULL' if value is None else "'" + str(value).replace("'", "''") + "'"


class Fixture:
	def __init__(self, event_id):
		self.id = int(event_id)

	def row(self):
		r = psql("SELECT is_public, quote_nullable(customer_identifier_type), quote_nullable(customer_organization_number),"
				 f" quote_nullable(customer_ssn), name, building_id, from_::date FROM bb_event WHERE id={self.id};")
		if not r:
			sys.exit(f'event {self.id} does not exist')
		p = r.split('|')
		return {'is_public': int(p[0]), 'type': p[1], 'orgnr': p[2], 'ssn': p[3], 'name': p[4], 'building_id': int(p[5]), 'date': p[6]}

	def set(self, is_public, type_sql, orgnr_sql='NULL', ssn_sql='NULL'):
		psql(f"UPDATE bb_event SET is_public={is_public}, customer_identifier_type={type_sql},"
			 f" customer_organization_number={orgnr_sql}, customer_ssn={ssn_sql} WHERE id={self.id};")


def opener(jar=None):
	handlers = [urllib.request.HTTPSHandler(context=CTX)]
	if jar is not None:
		handlers.append(urllib.request.HTTPCookieProcessor(jar))
	return urllib.request.build_opener(*handlers)


def schedule_entry(op, fx, r):
	with op.open(f"{BASE}/bookingfrontend/buildings/{r['building_id']}/schedule?date={r['date']}") as resp:
		d = json.load(resp)

	def walk(x):
		if isinstance(x, dict):
			if x.get('type') == 'event' and x.get('id') == fx.id:
				yield x
			for v in x.values():
				yield from walk(v)
		elif isinstance(x, list):
			for v in x:
				yield from walk(v)

	hits = list(walk(d))
	return hits[0] if hits else None


def shape(entry):
	"""What a viewer got: the name, and which gated keys carry a value (never the values)."""
	if entry is None:
		return '(event not in the response)', []
	return entry.get('name'), [k for k in GATED if entry.get(k) not in (None, '')]


def ids(entry):
	"""Which of customer_identifier_type / customer_organization_number the payload carries (never the values)."""
	return [] if entry is None else [k for k in IDS if entry.get(k) not in (None, '')]


def main():
	if len(sys.argv) != 2:
		sys.exit(__doc__)
	fx = Fixture(sys.argv[1])
	before = fx.row()
	print(f"fixture {fx.id} before: is_public={before['is_public']} type={before['type']} (identifiers not printed)")
	restore = (before['is_public'], before['type'], before['orgnr'], before['ssn'])
	anon = opener()
	jar = http.cookiejar.CookieJar()
	citizen = opener(jar)
	citizen.open(f"{BASE}/bookingfrontend/login/?after=" + urllib.parse.quote('/client/no')).read()
	with citizen.open(f'{BASE}/bookingfrontend/user') as resp:
		user = json.load(resp)
	ssn = user.get('ssn')
	orgs = [d.get('organization_number') for d in (user.get('delegates') or []) if d.get('active') and (d.get('organization_number') or '').strip()]
	if not ssn or not orgs:
		print(f'CONTROL FAILED - the citizen login gave {"an ssn" if ssn else "no ssn"} and {len(orgs)} active organisation(s) with a number; the owner arms need both')
		return 2
	results = []
	try:
		# --- C + N: owners keep everything (must hold on base AND tip) ---------------------------------------
		fx.set(0, "'organization_number'", lit(orgs[0]))
		entry = schedule_entry(citizen, fx, fx.row())
		name, keys = shape(entry)
		ok1 = name == before['name'] and {'secret', 'contact_name', 'organizer'} <= set(keys)
		results.append(('N1 owner by organisation number', ok1, f'name {name!r}; gated keys present: {keys}'))
		results.append(('N13 owner by organisation number keeps type + number (isOrgAdmin)', ids(entry) == IDS, f'present: {ids(entry)}'))
		fx.set(0, "'ssn'", 'NULL', lit(ssn))
		entry = schedule_entry(citizen, fx, fx.row())
		name, keys = shape(entry)
		ok2 = name == before['name'] and {'secret', 'contact_name', 'organizer', 'customer_ssn'} <= set(keys)
		results.append(('N2 owner by ssn', ok2, f'name {name!r}; gated keys present: {keys}'))
		results.append(('N13 owner by ssn keeps the type', ids(entry) == ['customer_identifier_type'], f'present: {ids(entry)}'))
		if not (ok1 and ok2):
			print('CONTROL FAILED - an owner does not see their own event on the served code; nothing below would mean anything')
			for label, ok, detail in results:
				print(f"{'GREEN' if ok else 'RED  '}  {label}: {detail}")
			return 2
		name, keys = shape(schedule_entry(anon, fx, fx.row()))
		results.append(('N2b the same event, no session', name == PLACEHOLDER and keys == [], f'name {name!r}; gated keys present: {keys}'))

		fx.set(1, "'organization_number'", lit(orgs[0]))
		entry = schedule_entry(anon, fx, fx.row())
		name, keys = shape(entry)
		results.append(('N4 public with a number, no session', name == before['name'] and 'secret' not in keys and 'customer_ssn' not in keys,
						f'name {name!r}; gated keys present: {keys}'))
		results.append(('F11 public with a number, no session: neither type nor number', ids(entry) == [], f'present: {ids(entry)}'))
		fx.set(0, "'organization_number'", lit(orgs[0]))
		entry = schedule_entry(anon, fx, fx.row())
		results.append(('F11 private with a number, no session: neither type nor number', ids(entry) == [], f'present: {ids(entry)}'))

		for is_public, want in ((1, 1), (0, 0)):
			fx.set(is_public, 'NULL')
			with anon.open(f'{BASE}/bookingfrontend/?menuaction=bookingfrontend.uievent.info&id={fx.id}') as resp:
				hits = resp.read().decode('utf-8', 'replace').count(before['name'])
			results.append((f"N12 legacy info page, {'public' if is_public else 'private'}", (hits > 0) == bool(want), f'name occurrences: {hits}'))

		# --- F: the empty-identifier rows ------------------------------------------------------------------
		fx.set(0, "'organization_number'", "''", "''")
		name, keys = shape(schedule_entry(anon, fx, fx.row()))
		results.append(("F1 no session, private, org-nr type + ''", name == PLACEHOLDER and keys == [], f'name {name!r}; gated keys present: {keys}'))
		name, keys = shape(schedule_entry(citizen, fx, fx.row()))
		results.append(("F4 logged-in non-owner, the same row", name == PLACEHOLDER and keys == [], f'name {name!r}; gated keys present: {keys}'))
		fx.set(0, "'ssn'", 'NULL', "''")
		name, keys = shape(schedule_entry(anon, fx, fx.row()))
		results.append(("F3 no session, private, ssn type + ''", name == PLACEHOLDER and keys == [], f'name {name!r}; gated keys present: {keys}'))
		fx.set(1, "'organization_number'", "''", "''")
		name, keys = shape(schedule_entry(anon, fx, fx.row()))
		results.append(("F6 no session, PUBLIC, org-nr type + ''", name == before['name'] and 'secret' not in keys and 'customer_ssn' not in keys,
						f'name {name!r}; gated keys present: {keys}'))
	finally:
		fx.set(*restore)
		r = fx.row()
		print(f"fixture {fx.id} restored: is_public={r['is_public']} type={r['type']} (identifiers as before: "
			  f"{(r['orgnr'], r['ssn']) == (restore[2], restore[3])})")

	print()
	red = 0
	for label, ok, detail in results:
		red += 0 if ok else 1
		print(f"{'GREEN' if ok else 'RED  '}  {label}: {detail}")
	return 1 if red else 0


if __name__ == '__main__':
	sys.exit(main())
