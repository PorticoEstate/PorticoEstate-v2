const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

function setup()
{
	const elements = new Map();
	const timers = new Map();
	const requests = [];
	let timerId = 0;
	function element(tag = '')
	{
		return {
			tag, value: '', hidden: false, disabled: false, children: [], handlers: {}, dataset: {},
			addEventListener(name, handler) { this.handlers[name] = handler; },
			append(...children) { this.children.push(...children); },
			appendChild(child) { this.children.push(child); },
			replaceChildren() { this.children = []; },
			removeAttribute(name) { delete this[name]; },
			focus() { this.focused = true; },
			select() { this.selected = true; },
			querySelectorAll(selector)
			{
				const children = this.children.flatMap(child => [child, ...child.querySelectorAll(selector)]);
				return children.filter(child => selector === '.information-screen-picker__resource'
					? child.className === 'information-screen-picker__resource'
					: child.type === 'checkbox' && child.checked);
			},
		};
	}
	function get(name)
	{
		if (!elements.has(name)) elements.set(name, element());
		return elements.get(name);
	}
	const picker = get('picker');
	picker.dataset = {
		buildingSearchUrl: '/portico/bookingfrontend/buildings/search',
		resourceListUrl: '/portico/bookingfrontend/buildings/__BUILDING_ID__/resources?results=-1&sort=name&short=1',
		informationScreenUrl: '/portico/bookingfrontend/?sessionid=temporary&kp3=temporary&click_history=temporary&domain=test',
		selectBuilding: 'Select building', noBuildings: 'No buildings', noResources: 'No resources',
		loadError: 'Load error', linkCopied: 'Copied', copyError: 'Copy error',
	};
	get('days').value = '2';
	const navigator = { clipboard: { writeText: async () => {} } };
	const context = {
		URL, navigator,
		window: {
			location: { href: 'http://localhost:8088/portico/bookingfrontend/view/information-screen-picker' },
			setTimeout(handler) { timers.set(++timerId, handler); return timerId; },
		},
		clearTimeout(id) { timers.delete(id); },
		document: {
			getElementById(id) { return get(id.replace(/^information-screen-/, '')); },
			createElement: element,
		},
		fetch(url) { return new Promise(resolve => requests.push({ url: new URL(url), resolve })); },
	};
	vm.runInNewContext(fs.readFileSync(path.join(__dirname,
		'../../src/modules/bookingfrontend/html/base/information_screen/bookingfrontend_information_screen.js'), 'utf8'), context);

	return {
		get, requests, timers, navigator,
		input(name, value) { get(name).value = value; get(name).handlers.input(); },
		runSearch()
		{
			const [id, handler] = [...timers][0];
			timers.delete(id);
			return handler();
		},
		async reply(index, data, ok = true)
		{
			requests[index].resolve({ ok, json: async () => data });
			await new Promise(setImmediate);
		},
	};
}

async function loadBuilding(harness)
{
	harness.input('building-search', 'Fana');
	const search = harness.runSearch();
	await harness.reply(0, { results: [{ id: 10, name: 'Fana kulturhus' }] });
	await search;
	harness.get('building-results').children[0].children[0].handlers.click();
	await harness.reply(1, { results: [{ id: 125, name: 'Kultursalen' }, { id: 106, name: 'Småsalen' }] });
}

test('builds a bookmarkable URL using REST resources and documented resource_id arrays', async () =>
{
	const h = setup();
	await loadBuilding(h);
	assert.equal(h.requests[0].url.pathname, '/portico/bookingfrontend/buildings/search');
	assert.equal(h.requests[0].url.searchParams.get('query'), 'Fana');
	assert.equal(h.requests[1].url.pathname, '/portico/bookingfrontend/buildings/10/resources');
	assert.equal(h.requests[1].url.searchParams.get('results'), '-1');
	const resources = h.get('resource-list').children;
	for (const resource of resources)
	{
		resource.children[0].checked = true;
		resource.children[0].handlers.change();
	}
	const link = new URL(h.get('link').value);
	assert.equal(link.origin, 'http://localhost:8088');
	assert.equal(link.searchParams.get('menuaction'), 'bookingfrontend.uibuilding.information_screen');
	assert.equal(link.searchParams.get('id'), '10');
	assert.equal(link.searchParams.get('weekend'), '2');
	assert.deepEqual(link.searchParams.getAll('resource_id[]'), ['125', '106']);
	assert.deepEqual([...link.searchParams.keys()], ['domain', 'menuaction', 'id', 'weekend', 'resource_id[]', 'resource_id[]']);
	h.get('days').value = '3';
	h.get('days').handlers.change();
	assert.equal(new URL(h.get('link').value).searchParams.get('weekend'), '3');
	for (const resource of resources)
	{
		resource.children[0].checked = false;
		resource.children[0].handlers.change();
	}
	assert.equal(h.get('link-panel').hidden, true);
	assert.equal(h.get('copy-link').disabled, true);
	assert.equal(h.get('open-link').href, undefined);
});

test('debounces building searches and ignores superseded responses', async () =>
{
	const h = setup();
	h.input('building-search', 'a');
	assert.equal(h.timers.size, 0);
	h.input('building-search', 'fa');
	h.input('building-search', 'fan');
	assert.equal(h.timers.size, 1);
	const oldSearch = h.runSearch();
	h.input('building-search', 'Fana');
	const latestSearch = h.runSearch();
	await h.reply(1, { results: [{ id: 10, name: 'Fana kulturhus' }] });
	await latestSearch;
	await h.reply(0, { results: [{ id: 20, name: 'Stale building' }] });
	await oldSearch;
	assert.equal(h.get('building-results').children[0].children[0].textContent, 'Fana kulturhus');
});

test('invalidates pending resources when the building changes and exposes API errors', async () =>
{
	const h = setup();
	h.input('building-search', 'Fana');
	const search = h.runSearch();
	await h.reply(0, { results: [{ id: 10, name: 'Fana kulturhus' }] });
	await search;
	h.get('building-results').children[0].children[0].handlers.click();
	h.input('building-search', 'Another');
	await h.reply(1, { results: [{ id: 125, name: 'Stale resource' }] });
	assert.equal(h.get('resource-panel').hidden, true);
	assert.equal(h.get('resource-list').children.length, 0);
	const failedSearch = h.runSearch();
	await h.reply(2, { error: 'Unavailable' }, false);
	await failedSearch;
	assert.equal(h.get('picker-status').textContent, 'Load error');
	assert.equal(h.get('link-panel').hidden, true);
	h.input('building-search', 'Retry');
	const invalidResponse = h.runSearch();
	await h.reply(3, { unexpected: [] });
	await invalidResponse;
	assert.equal(h.get('picker-status').textContent, 'Load error');
});

test('copies the current URL and leaves it selected for manual copying on clipboard failure', async () =>
{
	const h = setup();
	await loadBuilding(h);
	const checkbox = h.get('resource-list').children[0].children[0];
	checkbox.checked = true;
	checkbox.handlers.change();
	let copied;
	h.navigator.clipboard.writeText = async value => { copied = value; };
	await h.get('copy-link').handlers.click();
	assert.equal(copied, h.get('link').value);
	assert.equal(h.get('picker-status').textContent, 'Copied');
	h.navigator.clipboard.writeText = async () => { throw new Error('Permission denied'); };
	await h.get('copy-link').handlers.click();
	assert.equal(h.get('picker-status').textContent, 'Copy error');
	assert.equal(h.get('link').selected, true);
});
