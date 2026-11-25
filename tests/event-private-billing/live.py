#!/usr/bin/env python3
"""
Live arms for GH #1492 (task 48050), against a RUNNING stack: the officer's real event form over HTTP, the row it
stores, and what the front page's REST lane (the Next client's calendar source) gives a viewer with no session.
run.php is the hermetic harness; this is the same question asked of the served code.

⚠️ It WRITES: is_public and the invoice fields of ONE event, the fixture you name (your own test event, on a
development database). It saves that event through the edit form several times. Nothing else is written, and the
fixture is put back to the state it had before at the end.

Usage:
  PB_ADMIN_USER=... PB_ADMIN_PASS=... PB_PSQL_CONTAINER=... PB_PSQL_DB=... \
    python3 -I tests/event-private-billing/live.py <fixture event id>
Optional: PB_BASE (default https://pe-api.test), PB_PSQL_USER (default postgres),
          PB_PLACEHOLDER (default 'Privat arrangement', the nb placeholder the API gives a hidden name),
          PB_ORG_WITH_NUMBER (an organisation id with a 9-digit organization_number: adds the INFO M2d line).

Arms (the same names as run.php):
  C1  the instrument shows the fixture's REAL name while it is public (a hidden name later is not a dead probe);
  C2  the form shows «Fødselsnummer» for a stored ssn (the render probe can show something else than orgnr);
  M2a/M2b  nothing stored (NULL / ''): the form shows «Ikke angitt»;   M2c  a stored orgnr is shown as stored;
  M1c an event stored private with orgnr + no number is healed by an untouched save;
  M1a/M1b  set private with «Organisasjonsnummer»/«Fødselsnummer» and no number, save: no type stored, name hidden;
  R1  a number (999999999) is stored with its type and the name is hidden;   R2  «Ikke angitt»: hidden.
Exit: 0 all GREEN, 1 any RED, 2 a control failed (the run proves nothing).
"""
import html
import http.cookiejar
import json
import os
import ssl
import subprocess
import sys
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = os.environ.get('PB_BASE', 'https://pe-api.test')
PLACEHOLDER = os.environ.get('PB_PLACEHOLDER', 'Privat arrangement')

CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode = ssl.CERT_NONE


def need(name):
	v = os.environ.get(name)
	if not v:
		sys.exit(f'{name} is not set (see the usage at the top of this file)')
	return v


def psql(sql):
	out = subprocess.run(['docker', 'exec', need('PB_PSQL_CONTAINER'), 'psql', '-U', os.environ.get('PB_PSQL_USER', 'postgres'),
						  '-d', need('PB_PSQL_DB'), '-AtF', '|', '-c', sql], capture_output=True, text=True, check=True)
	return out.stdout.strip()


class Fixture:
	def __init__(self, event_id):
		self.id = int(event_id)

	def row(self):
		r = psql("SELECT is_public, quote_nullable(customer_identifier_type), quote_nullable(customer_organization_number),"
				 " quote_nullable(customer_ssn), customer_organization_id, name, building_id, from_::date"
				 f" FROM bb_event WHERE id={self.id}")
		if not r:
			sys.exit(f'event {self.id} does not exist')
		p = r.split('|')
		return {'is_public': int(p[0]), 'type': p[1], 'orgnr': p[2], 'ssn': p[3], 'org_id': p[4] or 'NULL',
				'name': p[5], 'building_id': int(p[6]), 'date': p[7]}

	def set(self, is_public, type_sql, orgnr_sql='NULL', ssn_sql='NULL', org_id_sql=None):
		extra = f', customer_organization_id={org_id_sql}' if org_id_sql is not None else ''
		psql(f"UPDATE bb_event SET is_public={is_public}, customer_identifier_type={type_sql},"
			 f" customer_organization_number={orgnr_sql}, customer_ssn={ssn_sql}{extra} WHERE id={self.id}")


class FormParser(HTMLParser):
	"""The controls of the form holding #field_customer_identifier_type, as a browser submits them."""

	def __init__(self):
		super().__init__(convert_charrefs=True)
		self.forms, self.cur, self.select, self.textarea = [], None, None, None

	def handle_starttag(self, tag, attrs):
		a = dict(attrs)
		if tag == 'form':
			self.cur = {'action': a.get('action', ''), 'fields': [], 'ids': set(), 'selects': {}}
			self.forms.append(self.cur)
			return
		if self.cur is None:
			return
		if a.get('id'):
			self.cur['ids'].add(a['id'])
		name = a.get('name')
		if tag == 'input' and name:
			t = (a.get('type') or 'text').lower()
			if t in ('submit', 'button', 'image', 'reset', 'file') or (t in ('checkbox', 'radio') and 'checked' not in a):
				return
			self.cur['fields'].append((name, a.get('value', 'on' if t in ('checkbox', 'radio') else '')))
		elif tag == 'select' and name:
			self.select = {'name': name, 'id': a.get('id'), 'first': None, 'selected': None}
		elif tag == 'option' and self.select is not None:
			v = a.get('value')
			if self.select['first'] is None:
				self.select['first'] = v
			if 'selected' in a and self.select['selected'] is None:
				self.select['selected'] = v
		elif tag == 'textarea' and name:
			self.textarea = [name, '']

	def handle_data(self, data):
		if self.textarea is not None:
			self.textarea[1] += data

	def handle_endtag(self, tag):
		if tag == 'form':
			self.cur = None
		elif tag == 'select' and self.select is not None:
			s = self.select
			val = s['selected'] if s['selected'] is not None else s['first']
			if self.cur is not None:
				self.cur['fields'].append((s['name'], val or ''))
				self.cur['selects'][s['id'] or s['name']] = val or ''
			self.select = None
		elif tag == 'textarea' and self.textarea is not None:
			if self.cur is not None:
				self.cur['fields'].append(tuple(self.textarea))
			self.textarea = None


class Officer:
	def __init__(self, event_id):
		self.edit_url = f'{BASE}/?menuaction=booking.uievent.edit&id={event_id}'
		jar = http.cookiejar.CookieJar()
		self.op = urllib.request.build_opener(urllib.request.HTTPSHandler(context=CTX), urllib.request.HTTPCookieProcessor(jar))
		self.op.open(BASE + '/login', urllib.parse.urlencode({'login': need('PB_ADMIN_USER'), 'passwd': need('PB_ADMIN_PASS'),
															   'logindomain': 'default'}).encode()).read()
		# The first page after /login is bounced via ?_deeplink_path=/login&second_redirect=1 and lands elsewhere.
		self.op.open(self.edit_url).read()

	def form(self):
		p = FormParser()
		p.feed(self.op.open(self.edit_url).read().decode('utf-8', 'replace'))
		for f in p.forms:
			if 'field_customer_identifier_type' in f['ids']:
				return f
		sys.exit('the event form was not found (login failed, or no access to the event?)')

	def shown(self):
		f = self.form()
		fields = dict(f['fields'])
		return f['selects'].get('field_customer_identifier_type'), fields.get('customer_organization_number')

	def save(self, overrides):
		f = self.form()
		fields = [(k, v) for k, v in f['fields'] if k not in overrides] + list(overrides.items())
		action = html.unescape(f['action']) or self.edit_url
		url = action if action.startswith('http') else BASE + (action if action.startswith('/') else '/' + action)
		self.op.open(url, urllib.parse.urlencode(fields).encode()).read()


def front_name(fx, r):
	"""The name GET /bookingfrontend/buildings/{id}/schedule gives a viewer with no session."""
	d = json.load(urllib.request.urlopen(f"{BASE}/bookingfrontend/buildings/{r['building_id']}/schedule?date={r['date']}", context=CTX))

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
	return hits[0].get('name') if hits else '(event not in the response)'


def main():
	if len(sys.argv) != 2:
		sys.exit(__doc__)
	fx = Fixture(sys.argv[1])
	before = fx.row()
	print(f'fixture {fx.id} before: {before}')
	restore = (before['is_public'], before['type'], before['orgnr'], before['ssn'], before['org_id'])
	officer = Officer(fx.id)
	results = []
	try:
		fx.set(0, "'ssn'", "''", "'01010112345'")
		c2 = officer.shown()[0]
		print(f"C2 stored ssn -> shown {c2!r} (must be 'ssn')")
		if c2 != 'ssn':
			print('CONTROL C2 FAILED - this run proves nothing')
			return 2

		for label, type_sql in (('M2a stored NULL', 'NULL'), ("M2b stored ''", "''")):
			fx.set(0, type_sql)
			got = officer.shown()[0]
			results.append((label, got == '', f"shown {got!r}, want '' («Ikke angitt»)"))
		fx.set(0, "'organization_number'", "''")
		got = officer.shown()[0]
		results.append(("M2c stored orgnr, no number", got == 'organization_number', f"shown {got!r}, want 'organization_number' (as stored)"))
		org = os.environ.get('PB_ORG_WITH_NUMBER')
		if org:
			fx.set(0, 'NULL', org_id_sql=str(int(org)))
			print(f"INFO M2d stored NULL + organisation {org} -> shown {officer.shown()!r} (option B suggests its number)")
			fx.set(0, 'NULL', org_id_sql=restore[4])

		fx.set(0, "'organization_number'", "''")
		officer.save({})
		r = fx.row()
		name = front_name(fx, r)
		results.append(('M1c stored private orgnr + no number, untouched save', r['is_public'] == 0 and name == PLACEHOLDER,
						f"stored type={r['type']} orgnr={r['orgnr']}; front page name {name!r}"))

		for label, billing in (('M1a «Organisasjonsnummer», no number', {'customer_identifier_type': 'organization_number', 'customer_organization_number': ''}),
							   ('M1b «Fødselsnummer», no number', {'customer_identifier_type': 'ssn', 'customer_ssn': ''})):
			fx.set(1, 'NULL')
			c1 = front_name(fx, fx.row())
			print(f"C1 [{label}] public -> front page name {c1!r} (must be the real name {before['name']!r})")
			if c1 != before['name']:
				print('CONTROL C1 FAILED - this run proves nothing')
				return 2
			officer.save({'is_public': '0', **billing})
			r = fx.row()
			name = front_name(fx, r)
			results.append((label, r['is_public'] == 0 and r['type'] in ('NULL', "''") and name == PLACEHOLDER,
							f"stored is_public={r['is_public']} type={r['type']} orgnr={r['orgnr']} ssn={r['ssn']}; front page name {name!r}"))

		fx.set(1, 'NULL')
		officer.save({'is_public': '0', 'customer_identifier_type': 'organization_number', 'customer_organization_number': '999999999'})
		r = fx.row()
		name = front_name(fx, r)
		results.append(('R1 «Organisasjonsnummer» 999999999', r['type'] == "'organization_number'" and r['orgnr'] == "'999999999'" and name == PLACEHOLDER,
						f"stored type={r['type']} orgnr={r['orgnr']}; front page name {name!r}"))
		fx.set(1, 'NULL')
		officer.save({'is_public': '0', 'customer_identifier_type': ''})
		r = fx.row()
		name = front_name(fx, r)
		results.append(('R2 «Ikke angitt»', r['type'] in ('NULL', "''") and name == PLACEHOLDER, f"stored type={r['type']}; front page name {name!r}"))
	finally:
		fx.set(restore[0], restore[1], restore[2], restore[3], restore[4])
		print(f'fixture {fx.id} restored: {fx.row()}')

	print()
	red = 0
	for label, ok, detail in results:
		red += 0 if ok else 1
		print(f"{'GREEN' if ok else 'RED  '}  {label}: {detail}")
	return 1 if red else 0


if __name__ == '__main__':
	sys.exit(main())
