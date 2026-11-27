const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../src/modules/booking/js/base/hospitality_order_show.js'), 'utf8');

function fixture()
{
	const container = { dataset: {} };
	const cost = { value: '8937.50', readOnly: true };
	const label = { dataset: { costLabel: 'Cost', hospitalityLabel: 'Cost including hospitality' } };
	let inputs = 0;
	let purchase = null;
	const document = {
		getElementById(id)
		{
			return {
				hospitality_orders_container: container,
				field_cost: cost,
				field_cost_label: label
			}[id];
		},
		querySelector() { return purchase; }
	};
	const context = { document, $: () => ({ ready() {}, trigger() { inputs++; } }) };
	vm.runInNewContext(source, context);
	return { context, container, cost, label, inputCount: () => inputs, setPurchase: value => { purchase = value; } };
}

test('hospitality totals include per-line VAT and exclude cancelled orders', () =>
{
	const { context } = fixture();
	const result = context.calculate_hospitality_orders([
		{ status: 'pending', lines: [{ amount: '360.00', tax_percent: 25 }, { amount: '1600.00', tax_percent: 25 }] },
		{ status: 'confirmed', lines: [{ amount: '0.02', tax_percent: 25 }, { amount: '1.00', tax_percent: 0 }] },
		{ status: 'cancelled', lines: [{ amount: '500.00', tax_percent: null }] }
	]);
	assert.equal(result.inclusiveTotal, 245103);
	assert.equal(result.lines.length, 4);
	assert.equal(context.calculate_hospitality_orders([{ status: 'pending', lines: [{ amount: '1.00', tax_percent: null }] }]), null);
	assert.equal(context.calculate_hospitality_orders({}), null);
	assert.equal(context.calculate_hospitality_orders([]).inclusiveTotal, 0);
});

test('article cost waits for both totals and updates the submitted field and label', () =>
{
	const { context, container, cost, label, inputCount, setPurchase } = fixture();
	setPurchase({ textContent: '8937.50' });
	assert.equal(context.update_event_cost(), false);
	assert.equal(cost.value, '8937.50');
	container.dataset.inclusiveTotal = '245000';
	container.dataset.hasLines = 'true';
	assert.equal(context.update_event_cost(), true);
	assert.equal(cost.value, '11387.50');
	assert.equal(label.textContent, 'Cost including hospitality');
	setPurchase({ textContent: '9750.00' });
	context.update_event_cost();
	assert.equal(cost.value, '12200.00');
	assert.equal(inputCount(), 2);
	delete container.dataset.inclusiveTotal;
	assert.equal(context.update_event_cost(), false);
	assert.equal(cost.value, '12200.00');
});

test('manual cost remains the entered total and no hospitality restores the normal label', () =>
{
	const { context, container, cost, label } = fixture();
	cost.readOnly = false;
	container.dataset.inclusiveTotal = '245000';
	container.dataset.hasLines = 'true';
	assert.equal(context.update_event_cost(), true);
	assert.equal(cost.value, '8937.50');
	assert.equal(label.textContent, 'Cost including hospitality');
	container.dataset.inclusiveTotal = '0';
	container.dataset.hasLines = 'false';
	context.update_event_cost();
	assert.equal(cost.value, '8937.50');
	assert.equal(label.textContent, 'Cost');
});
