(function ()
{
	'use strict';
	var form = document.getElementById('calendar-holiday-form');
	if (!form) return;
	var error = document.getElementById('calendar-holiday-error');
	form.addEventListener('submit', function (event)
	{
		event.preventDefault();
		error.textContent = '';
		var payload = {};
		new FormData(form).forEach(function (value, key) { payload[key] = value; });
		if (payload.year) payload.occurence = 0;
		payload.observance_rule = form.elements.observance_rule.checked ? 1 : 0;
		fetch(form.dataset.apiUrl, {
			method: form.elements.hol_id.value > 0 ? 'PUT' : 'POST',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify(payload)
		}).then(function (response)
		{
			return response.json().then(function (data)
			{
				if (!response.ok) throw new Error(data.error || 'Save failed');
				return data;
			});
		}).then(function ()
		{
			window.location.href = form.dataset.listUrl;
		}).catch(function (err) { error.textContent = err.message; });
	});
})();
