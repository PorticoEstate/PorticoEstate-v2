const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

function setup(baseUrl)
{
	const context = {
		URL, URLSearchParams, strBaseURL: baseUrl,
		window: { location: { href: 'http://localhost/portico/email/view/compose' } },
		document: { addEventListener() {} }
	};
	vm.createContext(context);
	for (const file of ['src/modules/phpgwapi/js/core/base.js', 'src/modules/email/html/base/url.js'])
	{
		vm.runInContext(fs.readFileSync(path.join(__dirname, '../..', file), 'utf8'), context);
	}
	return context;
}

test('address-book query preserves encoding and puts explicit click_history last', () =>
{
	const context = setup('/portico/?click_history=current');
	const url = new URL(context.window.emailLink('/portico/email/api/addressbook?click_history=original', {
		q: 'Sigurd & +', filter: 'none'
	}));
	assert.equal(url.pathname, '/portico/email/api/addressbook');
	assert.equal(url.searchParams.get('q'), 'Sigurd & +');
	assert.equal(url.searchParams.get('filter'), 'none');
	assert.deepEqual(url.searchParams.getAll('click_history'), ['original']);
	assert.equal([...url.searchParams.keys()].at(-1), 'click_history');
});

test('folder navigation preserves context and fragment while removing pagination', () =>
{
	const context = setup('/portico/?click_history=current');
	const url = new URL(context.window.emailLink('/portico/email/view/inbox?sort=1&start=25#messages', {
		'fldball[acctnum]': 2, 'fldball[folder]': 'A & B+ / C', start: null
	}));
	assert.equal(url.searchParams.get('fldball[folder]'), 'A & B+ / C');
	assert.equal(url.searchParams.get('fldball[acctnum]'), '2');
	assert.equal(url.searchParams.get('sort'), '1');
	assert.equal(url.searchParams.has('start'), false);
	assert.equal(url.hash, '#messages');
	assert.equal(url.searchParams.get('click_history'), 'current');
	assert.equal([...url.searchParams.keys()].at(-1), 'click_history');
});

test('root installation does not add a server prefix', () =>
{
	const context = setup('/?click_history=current');
	const url = new URL(context.window.emailLink('/email/api/addressbook', { q: 'user' }));
	assert.equal(url.pathname, '/email/api/addressbook');
	assert.equal([...url.searchParams.keys()].at(-1), 'click_history');
});

test('base without query does not produce an undefined parameter', () =>
{
	const context = setup('/portico/');
	const url = new URL(context.window.emailLink('/portico/email/api/addressbook', { q: 'user' }));
	assert.equal(url.searchParams.toString(), 'q=user');
});

test('external URLs are not rewritten', () =>
{
	const context = setup('/portico/?click_history=current');
	assert.equal(context.window.emailLink('https://example.test/path?q=value'), 'https://example.test/path?q=value');
});

test('contact search waits for two characters, debounces and ignores superseded results', async () =>
{
	const context = setup('/portico/?click_history=current');
	const timers = new Map();
	const requests = [];
	let timerId = 0;
	function element()
	{
		return {
			value: '', textContent: '', handlers: {}, children: [], events: [],
			addEventListener(name, handler) { this.handlers[name] = handler; },
			dispatchEvent(event) { this.events.push(event); },
			replaceChildren() { this.children = []; },
			appendChild(child) { this.children.push(child); }
		};
	}
	Object.assign(context, {
		AbortController, Event,
		root: { dataset: { contactsApiUrl: '/portico/email/api/addressbook' } },
		addressBook: element(), addressBookQuery: element(), addressBookFilter: element(),
		addressBookResults: element(), addressBookStatus: element(),
		setTimeout(handler, delay) { assert.equal(delay, 300); timers.set(++timerId, handler); return timerId; },
		clearTimeout(handle) { timers.delete(handle); },
		fetch(url, options) { return new Promise(resolve => requests.push({ url, options, resolve })); }
	});
	context.document.createElement = element;
	const compose = fs.readFileSync(path.join(__dirname, '../../src/modules/email/html/base/compose.js'), 'utf8');
	const start = compose.indexOf('\tlet contactSearchTimer;');
	const end = compose.indexOf('\n\troot.querySelectorAll', start);
	assert.ok(start >= 0 && end > start);
	vm.runInContext(compose.slice(start, end), context);
	context.addressBookQuery.value = ' s ';
	context.addressBookQuery.handlers.input();
	assert.equal(timers.size, 0);
	assert.equal(requests.length, 0);
	context.addressBookQuery.value = 'si';
	context.addressBookQuery.handlers.input();
	context.addressBookQuery.value = 'sig';
	context.addressBookQuery.handlers.input();
	assert.equal(timers.size, 1);
	const firstSearch = [...timers.values()][0]();
	assert.equal(requests.length, 1);
	assert.equal(new URL(requests[0].url).searchParams.get('q'), 'sig');
	context.addressBookQuery.value = 'sigurd';
	context.addressBookQuery.handlers.input();
	assert.equal(requests[0].options.signal.aborted, true);
	const latestSearch = [...timers.values()][0]();
	requests[1].resolve({ ok: true, json: async () => ({ items: [] }) });
	await latestSearch;
	requests[0].resolve({ ok: true, json: async () => ({ items: [{ name: 'Stale', email: 'stale@example.test' }] }) });
	await firstSearch;
	assert.equal(context.addressBookStatus.textContent, '0 contacts');
	assert.equal(context.addressBookResults.children.length, 0);
	context.addressBookQuery.value = '';
	context.addressBookQuery.handlers.input();
	assert.equal(timers.size, 0);
	assert.equal(context.addressBookStatus.textContent, '');
	context.addressBookQuery.value = 'ab';
	context.addressBookQuery.handlers.input();
	context.addressBook.handlers.close();
	assert.equal(timers.size, 0);
	context.addressBookQuery.handlers.input();
	const contactSearch = [...timers.values()][0]();
	requests[2].resolve({ ok: true, json: async () => ({ items: [{ name: 'Contact', email: 'person@example.test' }] }) });
	await contactSearch;
	const selectContact = context.addressBookResults.children[0].children[0].handlers.click;
	for (const existing of [
		'person@example.test', 'PERSON@example.test',
		'Other <other@example.test>; "Surname, Name" <PERSON@example.test>',
		'other@example.test, Person <person@example.test>'
	])
	{
		context.recipientTarget = element();
		context.recipientTarget.value = existing;
		selectContact();
		assert.equal(context.recipientTarget.value, existing);
		assert.equal(context.recipientTarget.events.length, 0);
	}
	context.recipientTarget = element();
	selectContact();
	selectContact();
	assert.equal(context.recipientTarget.value, 'person@example.test');
	assert.equal(context.recipientTarget.events.length, 1);
	context.recipientTarget = element();
	context.recipientTarget.value = 'otherperson@example.test';
	selectContact();
	assert.equal(context.recipientTarget.value, 'otherperson@example.test, person@example.test');
});