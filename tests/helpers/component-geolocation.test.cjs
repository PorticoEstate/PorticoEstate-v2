const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

function setup(geolocation = '')
{
	const maps = [];
	const alerts = [];
	const requests = [];
	const elements = new Map();
	function element()
	{
		return {
			children: [], attributes: {}, visible: false,
			appendChild(child) { this.children.push(child); },
			setAttribute(name, value) { this.attributes[name] = value; },
		};
	}
	function get(id)
	{
		if (!elements.has(id)) elements.set(id, element());
		return elements.get(id);
	}
	function $(selector)
	{
		if (typeof selector === 'function') return selector();
		const node = get(selector);
		return {
			node,
			on() { return this; },
			detach() { return this; },
			empty() { node.children = []; return this; },
			append(child) { node.children.push(child.node); return this; },
			show() { node.visible = true; return this; },
		};
	}
	class Options
	{
		constructor(options) { Object.assign(this, options); }
	}
	class VectorSource extends Options
	{
		clear() { this.features = []; }
		addFeature(feature) { this.features.push(feature); }
	}
	class MapStub extends Options
	{
		constructor(options)
		{
			super(options);
			this.handlers = {};
			maps.push(this);
		}
		addLayer(layer) { this.layers.push(layer); }
		addOverlay(overlay) { this.overlay = overlay; }
		on(event, handler) { this.handlers[event] = handler; }
		dispose() { this.disposed = true; }
	}
	const context = {
		$, geolocation,
		alert(message) { alerts.push(message); },
		navigator: {
			geolocation: {
				getCurrentPosition(success, error) { requests.push({success, error}); },
			},
		},
		document: {
			getElementById(id) { return get('#' + id); },
			createElement: element,
			createTextNode(text) { return {text}; },
		},
		ol: {
			Map: MapStub, View: Options, Feature: Options,
			Overlay: class extends Options { setPosition(position) { this.position = position; } },
			control: {
				defaults: {defaults() { return {extend() { return []; }}; }},
				FullScreen: Options,
			},
			layer: {
				Tile: Options,
				Vector: class extends Options { getSource() { return this.source; } },
			},
			source: {OSM: Options, Vector: VectorSource},
			geom: {Point: class { constructor(coordinates) { this.coordinates = coordinates; } }},
			proj: {fromLonLat(coordinates) { return coordinates; }, transform(coordinates) { return coordinates; }},
		},
		console: {log() {}},
	};
	vm.runInNewContext(fs.readFileSync(path.join(__dirname,
		'../../src/modules/controller/js/base/edit_component.js'), 'utf8'), context);
	return {
		context, maps, requests, alerts, get,
		deny() { requests.at(-1).error({code: 1, PERMISSION_DENIED: 1}); },
	};
}

test('shows saved coordinates on load without requesting browser location', () =>
{
	const h = setup(' 0, 0 ');
	assert.equal(h.requests.length, 0);
	assert.equal(h.maps.length, 1);
	assert.equal(h.maps[0].view.zoom, 18);
	assert.deepEqual(Array.from(h.maps[0].view.center), [0, 0]);
	assert.equal(h.maps[0].layers[1].source.features.length, 1);
	assert.equal(h.get('#map').visible, true);
	assert.equal(h.maps[0].handlers.singleclick, undefined);
});

test('denied permission retains the saved position and allows manual selection on repeated updates', () =>
{
	const h = setup('10, 20');
	h.context.update_geolocation(1, 2);
	h.deny();
	h.context.update_geolocation(1, 2);
	h.deny();
	assert.equal(h.maps.filter(map => !map.disposed).length, 1);
	const map = h.maps.at(-1);
	assert.deepEqual(Array.from(map.view.center), [20, 10]);
	assert.equal(h.get('#map').visible, true);
	assert.equal(h.get('#map').children.length, 1);
	assert.equal(h.alerts.length, 2);
	map.handlers.singleclick({coordinate: [21, 11]});
	map.handlers.singleclick({coordinate: [22, 12]});
	assert.equal(map.layers.length, 2);
	assert.equal(map.layers[1].source.features.length, 1);
	assert.deepEqual(Array.from(map.layers[1].source.features[0].geometry.coordinates), [22, 12]);
	assert.equal(h.get('#popup-content').children.at(-1).attributes.onclick, 'set_geolocation(1,2,12,22)');
});

test('without a saved location, denial leaves an overview without an invented marker', () =>
{
	const h = setup();
	assert.equal(h.maps.length, 0);
	h.context.update_geolocation(1, 2);
	h.deny();
	const map = h.maps[0];
	assert.equal(h.get('#map').visible, true);
	assert.equal(map.view.zoom, 2);
	assert.equal(map.layers[1].source.features.length, 0);
	assert.equal(map.overlay.position, undefined);
	map.handlers.singleclick({coordinate: [20, 10]});
	assert.equal(map.layers[1].source.features.length, 1);
	assert.equal(h.get('#popup-content').children.at(-1).attributes.onclick, 'set_geolocation(1,2,10,20)');
});

test('successful browser location replaces the fallback map without saving automatically', () =>
{
	const h = setup('10, 20');
	h.context.update_geolocation(1, 2);
	h.requests[0].success({coords: {latitude: 11, longitude: 21}});
	assert.equal(h.maps.filter(map => !map.disposed).length, 1);
	assert.deepEqual(Array.from(h.maps.at(-1).view.center), [21, 11]);
	assert.equal(h.alerts.length, 0);
});

test('invalid saved coordinates are reported and not rendered', () =>
{
	for (const saved of ['91,20', '10,181', 'NaN,20', ',20'])
	{
		const h = setup(saved);
		assert.equal(h.maps.length, 0);
		assert.equal(h.alerts.length, 1);
	}
});
