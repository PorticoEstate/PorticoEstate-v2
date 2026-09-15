(function ()
{
	'use strict';

	var config = window.__calendarEventForm || { lang: {} };
	var form = document.getElementById('calendar-event-form');
	var error = document.getElementById('calendar-event-form-error');
	if (!form) return;

	form.addEventListener('submit', function (event)
	{
		event.preventDefault();
		error.hidden = true;
		var payload = {};
		new FormData(form).forEach(function (value, key)
		{
			if (key === 'category' || key === 'recur_days')
			{
				payload[key] = payload[key] || [];
				payload[key].push(value);
			}
			else
			{
				payload[key] = value;
			}
		});
		payload.private = form.elements.private.checked ? 1 : 0;
		payload.owner_participates = form.elements.owner_participates.checked ? 1 : 0;

		fetch(form.dataset.apiUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify(payload)
		}).then(function (response)
		{
			return response.json().then(function (data)
			{
				if (!response.ok) throw new Error(data.error || config.lang.saveFailed || 'Save failed');
				return data;
			});
		}).then(function (data)
		{
			window.location.href = data.view_url || form.dataset.listUrl;
		}).catch(function (err)
		{
			error.textContent = err.message || config.lang.saveFailed || 'Save failed';
			error.hidden = false;
		});
	});
})();