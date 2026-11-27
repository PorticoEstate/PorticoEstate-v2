
/* global template_set, lang, initialSelection */

$(document).ready(function ()
{
	function populateTableChkHospitality(application_id, reservation_type, reservation_id)
	{
		const queryString = window.location.search;
		const urlParams = new URLSearchParams(queryString);
		const menuaction = urlParams.get('menuaction');
		var oArgs;
		var url;


		oArgs = {
			application_id: application_id
		};
		url = phpGWLink('booking/hospitality-orders', oArgs);


		var container = 'hospitality_orders_container';
		var colDefsRegulations = [
			{
				key: 'name',
				label: lang['article'],
				attrs: [
					{ name: 'style', value: "text-align:left;" }
				]
			},
			{
				key: 'lang_unit',
				label: lang['unit'],
				attrs: [
					{ name: 'style', value: "text-align:center;" }
				]
			},
			{
				key: 'ex_tax_price',
				label: lang['unit cost'],
				attrs: [
					{ name: 'style', value: "text-align:right;" }
				]
			},
			{
				key: 'tax',
				label: lang['tax'],
				attrs: [
					{ name: 'style', value: "text-align:right;" }
				]
			},
			{
				key: 'selected_quantity',
				label: lang['quantity'],
				attrs: [
					{ name: 'style', value: "text-align:right;" }
				]
			},
			{
				key: 'selected_sum',
				label: lang['Sum'],
				attrs: [
					{ name: 'class', value: "selected_sum" },
					{ name: 'style', value: "text-align:right;" }
				]
			}

		];

		populateTableHospitality(url, container, colDefsRegulations);

	}

	if (typeof (reservation_type) === 'undefined')
	{
		reservation_type = '';
	}
	if (typeof (reservation_id) === 'undefined')
	{
		reservation_id = '';
	}

	if (typeof (application_id) !== 'undefined' && application_id)
	{
		populateTableChkHospitality(application_id, reservation_type, reservation_id);
	}
	else
	{
		var container = document.getElementById('hospitality_orders_container');
		if (container)
		{
			container.dataset.inclusiveTotal = '0';
			container.dataset.hasLines = 'false';
			update_event_cost();
		}
	}

	var form = document.getElementById('form');
	if (form)
	{
		form.addEventListener('submit', function (event)
		{
			var cost = document.getElementById('field_cost');
			if (cost && cost.readOnly && !update_event_cost())
			{
				event.preventDefault();
				alertify.error(hospitality_cost_error());
			}
		}, true);
	}

});

function update_event_cost()
{
	var container = document.getElementById('hospitality_orders_container');
	var purchaseTotal = document.querySelector('#articles_container table tfoot #sum_price_table');
	var cost = document.getElementById('field_cost');
	var label = document.getElementById('field_cost_label');
	if (!container || !cost || container.dataset.inclusiveTotal === undefined)
	{
		return false;
	}
	if (cost.readOnly)
	{
		if (!purchaseTotal)
		{
			return false;
		}
		var purchaseValue = purchaseTotal.textContent;
		var purchase = Number(purchaseValue);
		var hospitality = Number(container.dataset.inclusiveTotal);
		if (purchaseValue.trim() === '' || !Number.isFinite(purchase) || !Number.isFinite(hospitality))
		{
			return false;
		}
		cost.value = ((Math.round(purchase * 100) + hospitality) / 100).toFixed(2);
		$(cost).trigger('input');
	}
	if (label)
	{
		label.textContent = container.dataset.hasLines === 'true'
			? label.dataset.hospitalityLabel : label.dataset.costLabel;
	}
	return true;
}

function hospitality_cost_error()
{
	var message = lang['Unable to calculate hospitality cost'];
	return message && message.charAt(0) !== '!' ? message : 'Unable to calculate hospitality cost.';
}

function calculate_hospitality_orders(orders)
{
	if (!Array.isArray(orders))
	{
		return null;
	}

	var lines = [];
	var inclusiveTotal = 0;
	for (var order of orders)
	{
		if (!order || order.status === 'cancelled')
		{
			if (!order) return null;
			continue;
		}
		if (!Array.isArray(order.lines)) return null;
		for (var line of order.lines)
		{
			if (!line || line.amount === null || line.amount === '' ||
				line.tax_percent === null || line.tax_percent === '' ||
				!Number.isFinite(Number(line.amount)) || !Number.isFinite(Number(line.tax_percent)))
			{
				return null;
			}
			var amountOre = Math.round(Number(line.amount) * 100);
			inclusiveTotal += amountOre + Math.round(amountOre * Number(line.tax_percent) / 100);
			lines.push({
				name: line.article_name,
				lang_unit: line.unit,
				ex_tax_price: line.unit_price,
				tax: line.tax_percent,
				selected_quantity: line.quantity,
				selected_sum: line.amount
			});
		}
	}
	return { lines: lines, inclusiveTotal: inclusiveTotal };
}

var post_handle_hospitality_table = function ()
{

	var tr = $('#hospitality_orders_container').find('tr')[1];

	if (!tr || typeof (tr) === 'undefined')
	{
		return;
	}

	var xTable = tr.parentNode.parentNode;

	set_hospitality_sum(xTable);
};



function set_hospitality_sum(xTable)
{
	var tableFooter = xTable.tFoot;
	if (tableFooter)
	{
		tableFooter.parentNode.removeChild(tableFooter);
	}
	var xTableBody = xTable.childNodes[1];
	var selected_sum = xTableBody.getElementsByClassName('selected_sum');

	var temp_total_sum = 0;
	for (var i = 0; i < selected_sum.length; i++)
	{
		if (selected_sum[i].innerHTML)
		{
			temp_total_sum = parseFloat(temp_total_sum) + parseFloat(selected_sum[i].innerHTML);
			selected_sum[i].innerHTML = parseFloat(selected_sum[i].innerHTML).toFixed(2);
		}
	}

	var tableFooter = document.createElement('tfoot');
	tableFooter.id = 'hospitality_orders_tfoot';
	var tableFooterTr = document.createElement('tr');
	var tableFooterTrTd = document.createElement('td');

	tableFooterTrTd.setAttribute('colspan', 5);
	tableFooterTrTd.innerHTML = "Sum:";
	tableFooterTr.appendChild(tableFooterTrTd);
	var tableFooterTrTd2 = document.createElement('td');
	tableFooterTrTd2.setAttribute('id', 'hospitality_orders_sum');
	tableFooterTrTd2.classList.add("text-right");

	tableFooterTrTd2.innerHTML = temp_total_sum.toFixed(2);

	tableFooterTr.appendChild(tableFooterTrTd2);

	tableFooter.appendChild(tableFooterTr);
	xTable.appendChild(tableFooter);

}


function populateTableHospitality(url, container, colDefs)
{
	var table_class = '';
	if (template_set === 'bootstrap')
	{
		table_class = 'table table-bordered table-hover table-sm table-responsive';
	}
	else if (template_set === 'bookingfrontend')
	{
		table_class = 'table table-striped';
	}
	else
	{
		table_class = 'pure-table pure-table-bordered';
	}

	var containerElement = document.getElementById(container);
	if (!containerElement)
	{
		return;
	}

	containerElement.innerHTML = '<table class="' + table_class + '" width="100%"><thead><tr></tr></thead><tbody><tr><td colspan="' + colDefs.length + '">Loading...</td></tr></tbody></table>';
	delete containerElement.dataset.inclusiveTotal;
	delete containerElement.dataset.hasLines;

	var table = containerElement.querySelector('table');
	var headRow = table.querySelector('thead tr');
	colDefs.forEach(function (column)
	{
		var header = document.createElement('th');
		header.textContent = column.label || '';
		if (column.attrs)
		{
			column.attrs.forEach(function (attr)
			{
				header.setAttribute(attr.name, attr.value);
			});
		}
		headRow.appendChild(header);
	});

	$.getJSON(url, function (orders)
	{
		var tbody = table.querySelector('tbody');
		tbody.innerHTML = '';

		var result = calculate_hospitality_orders(orders);
		if (!result)
		{
			var errorRow = document.createElement('tr');
			var errorCell = document.createElement('td');
			errorCell.colSpan = colDefs.length;
			errorCell.textContent = hospitality_cost_error();
			errorRow.appendChild(errorCell);
			tbody.appendChild(errorRow);
			console.error('Unable to calculate hospitality cost: invalid order lines or VAT.');
			return;
		}

		var lines = result.lines;
		containerElement.dataset.inclusiveTotal = String(result.inclusiveTotal);
		containerElement.dataset.hasLines = String(lines.length > 0);
		if (lines.length === 0)
		{
			var emptyRow = document.createElement('tr');
			var emptyCell = document.createElement('td');
			emptyCell.colSpan = colDefs.length;
			var emptyText = 'No records found';
			try
			{
				emptyText = JqueryPortico.i18n.datatable().emptyTable;
			}
			catch (err)
			{
			}
			emptyCell.textContent = emptyText;
			emptyRow.appendChild(emptyCell);
			tbody.appendChild(emptyRow);
			update_event_cost();
			return;
		}

		lines.forEach(function (line)
		{
			var row = document.createElement('tr');
			colDefs.forEach(function (column)
			{
				var cell = document.createElement('td');
				var value = line[column.key];
				cell.textContent = value === null || typeof value === 'undefined' ? '' : value;
				if (column.attrs)
				{
					column.attrs.forEach(function (attr)
					{
						cell.setAttribute(attr.name, attr.value);
					});
				}
				row.appendChild(cell);
			});
			tbody.appendChild(row);
		});

		set_hospitality_sum(table);
		update_event_cost();
	}).fail(function ()
	{
		var tbody = table.querySelector('tbody');
		tbody.innerHTML = '';
		var row = document.createElement('tr');
		var cell = document.createElement('td');
		cell.colSpan = colDefs.length;
		cell.textContent = hospitality_cost_error();
		row.appendChild(cell);
		tbody.appendChild(row);
	});
}
