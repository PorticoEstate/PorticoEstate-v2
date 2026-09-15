(function ()
{
	'use strict';

	var config = window.__calendarCustomFields;
	var root = document.querySelector('.custom-fields');
	if (!config || !root) return;

	var rows = document.getElementById('calendar-custom-fields-rows');
	var error = document.getElementById('calendar-custom-fields-error');
	var status = document.getElementById('calendar-custom-fields-status');
	var fields = [];

	function showMessage(element, message)
	{
		element.textContent = message || '';
		element.hidden = !message;
	}

	function input(type, value, attrs)
	{
		var element = document.createElement('input');
		element.type = type;
		element.value = value || '';
		Object.keys(attrs || {}).forEach(function (key)
		{
			element.setAttribute(key, attrs[key]);
		});
		return element;
	}

	function render()
	{
		rows.textContent = '';
		fields.forEach(function (field, index)
		{
			var tr = document.createElement('tr');
			tr.dataset.index = index;
			tr.dataset.id = field.id;
			tr.dataset.stock = field.stock ? '1' : '0';

			var nameCell = document.createElement('td');
			if (field.stock)
			{
				nameCell.textContent = field.label || field.name || field.id;
			}
			else
			{
				nameCell.appendChild(input('text', field.name, { name: 'name', maxlength: '40' }));
			}
			tr.appendChild(nameCell);

			[['length', '3'], ['shown', '3'], ['order', '4']].forEach(function (meta)
			{
				var cell = document.createElement('td');
				cell.appendChild(input('number', field[meta[0]], { name: meta[0], min: '0', max: meta[0] === 'length' ? '255' : '9999', size: meta[1] }));
				tr.appendChild(cell);
			});

			['title', 'disabled'].forEach(function (name)
			{
				var cell = document.createElement('td');
				var checkbox = input('checkbox', '1', { name: name });
				checkbox.checked = !!field[name];
				cell.appendChild(checkbox);
				tr.appendChild(cell);
			});

			var deleteCell = document.createElement('td');
			if (!field.stock)
			{
				var remove = document.createElement('button');
				remove.type = 'button';
				remove.className = 'custom-fields__delete';
				remove.textContent = 'Delete';
				remove.addEventListener('click', function ()
				{
					fields.splice(index, 1);
					render();
				});
				deleteCell.appendChild(remove);
			}
			tr.appendChild(deleteCell);

			rows.appendChild(tr);
		});
	}

	function collect()
	{
		return Array.prototype.map.call(rows.querySelectorAll('tr'), function (tr)
		{
			var index = Number(tr.dataset.index);
			var field = fields[index];
			return {
				id: field.id,
				name: field.stock ? field.id : tr.querySelector('[name="name"]').value,
				label: field.label || '',
				stock: field.stock ? 1 : 0,
				length: Number(tr.querySelector('[name="length"]').value || 0),
				shown: Number(tr.querySelector('[name="shown"]').value || 0),
				order: Number(tr.querySelector('[name="order"]').value || 0),
				title: tr.querySelector('[name="title"]').checked ? 1 : 0,
				disabled: tr.querySelector('[name="disabled"]').checked ? 1 : 0
			};
		});
	}

	function load()
	{
		showMessage(error, '');
		showMessage(status, config.lang.loading);
		fetch(config.apiUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (response) { return response.json(); })
			.then(function (payload)
			{
				fields = payload.data || [];
				showMessage(status, '');
				render();
			})
			.catch(function ()
			{
				showMessage(status, '');
				showMessage(error, config.lang.loadFailed);
			});
	}

	root.querySelector('[data-action="add-field"]').addEventListener('click', function ()
	{
		fields.push({ id: '', name: '', label: '', length: 0, shown: 0, order: (fields.length + 1) * 10, title: 0, disabled: 0, stock: 0 });
		render();
	});

	root.querySelector('[data-action="save-fields"]').addEventListener('click', function ()
	{
		showMessage(error, '');
		fetch(config.apiUrl, {
			method: 'PUT',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ fields: collect() })
		}).then(function (response)
		{
			return response.json().then(function (payload)
			{
				if (!response.ok) throw new Error(payload.error || config.lang.saveFailed);
				return payload;
			});
		}).then(function (payload)
		{
			fields = payload.data || [];
			showMessage(status, payload.message || config.lang.saved);
			render();
		}).catch(function (err)
		{
			showMessage(error, err.message || config.lang.saveFailed);
		});
	});

	load();
})();