(function ()
{
	'use strict';

	var config = window.__calendarEvent;
	var target = document.getElementById('calendar-event-detail');
	var error = document.getElementById('calendar-event-error');
	if (!config || !target) return;

	function text(tag, value, className)
	{
		var element = document.createElement(tag);
		element.textContent = value || '';
		if (className) element.className = className;
		return element;
	}

	function action(label, href)
	{
		var link = document.createElement('a');
		link.className = 'calendar-event-detail__action';
		link.href = href;
		link.textContent = label;
		return link;
	}

	function deleteButton(event)
	{
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'calendar-event-detail__action calendar-event-detail__action--danger';
		button.textContent = config.lang.delete;
		button.addEventListener('click', function ()
		{
			if (!window.confirm(config.lang.confirmDelete)) return;
			fetch(event.delete_url, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json' } })
				.then(function (response)
				{
					return response.json().then(function (payload)
					{
						if (!response.ok) throw new Error(payload.error || config.lang.loadFailed);
						return payload;
					});
				})
				.then(function ()
				{
					window.location.href = document.querySelector('.calendar-event-detail a').href;
				})
				.catch(function (err)
				{
					error.textContent = err.message || config.lang.loadFailed;
					error.hidden = false;
				});
		});
		return button;
	}

	function render(event)
	{
		target.textContent = '';
		target.appendChild(text('h1', event.title));
		target.appendChild(text('p', event.start_label + ' - ' + event.end_label, 'calendar-event-detail__time'));

		if (event.description)
		{
			target.appendChild(text('p', event.description, 'calendar-event-detail__description'));
		}

		var fields = document.createElement('dl');
		fields.className = 'calendar-event-detail__fields';
		(event.fields || []).forEach(function (field)
		{
			if (!field.value) return;
			fields.appendChild(text('dt', field.label));
			fields.appendChild(text('dd', field.value));
		});
		target.appendChild(fields);

		var actions = document.createElement('div');
		actions.className = 'calendar-event-detail__actions';
		actions.appendChild(action(config.lang.edit, event.edit_url));
		actions.appendChild(deleteButton(event));
		actions.appendChild(action(config.lang.exportEvent, event.export_url));
		target.appendChild(actions);

		target.hidden = false;
	}

	fetch(config.apiUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
		.then(function (response)
		{
			return response.json().then(function (payload)
			{
				if (!response.ok) throw new Error(payload.error || config.lang.loadFailed);
				return payload;
			});
		})
		.then(function (payload)
		{
			render(payload.data || {});
		})
		.catch(function (err)
		{
			error.textContent = err.message || config.lang.loadFailed;
			error.hidden = false;
		});
})();