const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Element
{
	constructor(tag, className = '')
	{
		this.tag = tag;
		this.className = className;
		this.children = [];
		this.parentElement = null;
		this.nodeType = 1;
	}
	get parentNode() { return this.parentElement; }
	get firstChild() { return this.children[0] || null; }
	get classList() { return { contains: name => this.className.split(/\s+/).includes(name) }; }
	appendChild(child) { return this.insertBefore(child, null); }
	insertBefore(child, before)
	{
		if (child.parentElement)
		{
			const old = child.parentElement.children;
			old.splice(old.indexOf(child), 1);
		}
		const index = before ? this.children.indexOf(before) : this.children.length;
		this.children.splice(index, 0, child);
		child.parentElement = this;
		return child;
	}
	matches()
	{
		return this.tag === 'table' && (this.classList.contains('pure-table') || this.classList.contains('table-responsive'));
	}
	closest(selector)
	{
		for (let element = this; element; element = element.parentElement)
		{
			if (selector.split(', ').some(item =>
				item[0] === '.' && element.classList.contains(item.slice(1)) ||
				item[0] === '#' && element.id === item.slice(1)))
			{
				return element;
			}
		}
		return null;
	}
	querySelectorAll()
	{
		return this.children.flatMap(child => [
			...(child.matches() ? [child] : []),
			...child.querySelectorAll()
		]);
	}
}

function setup(body)
{
	const source = fs.readFileSync(path.join(__dirname, '../src/modules/phpgwapi/js/core/base.js'), 'utf8');
	const start = source.indexOf('(function ()\n{\n\tvar unscrollableAncestors');
	assert.notEqual(start, -1);
	let ready;
	let mutation;
	const document = {
		body,
		createElement: tag => new Element(tag),
		addEventListener: (event, callback) => { if (event === 'DOMContentLoaded') ready = callback; }
	};
	class MutationObserver
	{
		constructor(callback) { mutation = callback; }
		observe() {}
	}
	vm.runInNewContext(source.slice(start), { document, MutationObserver, Node: { ELEMENT_NODE: 1 } });
	ready();
	return node => mutation([{ addedNodes: [node] }]);
}

test('wraps inline Pure and Bootstrap tables without nesting existing scroll containers', () =>
{
	const body = new Element('body');
	const pure = body.appendChild(new Element('div')).appendChild(new Element('table', 'pure-table'));
	const bootstrap = body.appendChild(new Element('div')).appendChild(new Element('table', 'table table-responsive'));
	const responsive = body.appendChild(new Element('div', 'table-responsive'));
	const existing = responsive.appendChild(new Element('table', 'pure-table'));
	const datatable = body.appendChild(new Element('div', 'dt-container'));
	const data = datatable.appendChild(new Element('table', 'pure-table'));
	setup(body);
	assert.equal(pure.parentElement.className, 'pure-table-scroll');
	assert.equal(bootstrap.parentElement.className, 'pure-table-scroll');
	assert.equal(existing.parentElement, responsive);
	assert.equal(data.parentElement, datatable);
});

test('wraps dynamically added tables and reuses an empty wrapper on replacement', () =>
{
	const body = new Element('body');
	const notify = setup(body);
	const container = body.appendChild(new Element('div'));
	const first = container.appendChild(new Element('table', 'table-responsive'));
	notify(first);
	const wrapper = first.parentElement;
	wrapper.children.splice(0, 1);
	first.parentElement = null;
	const replacement = container.appendChild(new Element('table', 'table-responsive'));
	notify(replacement);
	assert.equal(replacement.parentElement, wrapper);
	assert.equal(container.children.length, 1);
});
