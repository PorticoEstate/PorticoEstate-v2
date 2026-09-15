(function ()
{
	'use strict';

	var config = window.__calendarEventForm || { lang: {} };
	var form = document.getElementById('calendar-event-form');
	var error = document.getElementById('calendar-event-form-error');
	var lookup = document.getElementById('participant-lookup');
	var participantType = document.getElementById('participant-type');
	var participantCategory = document.getElementById('participant-category');
	var participantResults = document.getElementById('participant-results');
	var eventParticipants = document.getElementById('event-participants');
	if (!form) return;

	function buildUrl(href, params)
	{
		var url = new URL(href, window.location.origin);
		Object.keys(params).forEach(function (key)
		{
			url.searchParams.set(key, params[key]);
		});
		return url.toString();
	}

	function selectedParticipants()
	{
		return Array.prototype.map.call(eventParticipants.options, function (option)
		{
			return { id: option.value, status: option.dataset.status || 'A' };
		});
	}

	function addOption(target, participant)
	{
		if (!participant.id) return;
		for (var i = 0; i < target.options.length; i++)
		{
			if (target.options[i].value === participant.id) return;
		}
		var option = document.createElement('option');
		option.value = participant.id;
		option.textContent = participant.name + (participant.type ? ' (' + participant.type + ')' : '');
		option.dataset.status = 'A';
		target.appendChild(option);
	}

	function searchParticipants()
	{
		var value = lookup.value.trim();
		if (value.length < 3 && value !== '*') return;
		fetch(buildUrl(form.dataset.participantsUrl, {
			lookup: value,
			type: participantType.value,
			cat_id: participantCategory.value
		}), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (response) { return response.json(); })
			.then(function (payload)
			{
				participantResults.textContent = '';
				(payload.data || []).forEach(function (participant)
				{
					addOption(participantResults, participant);
				});
			});
	}

	form.querySelector('[data-action="search-participants"]').addEventListener('click', searchParticipants);
	lookup.addEventListener('keydown', function (event)
	{
		if (event.key === 'Enter')
		{
			event.preventDefault();
			searchParticipants();
		}
	});
	form.querySelector('[data-action="add-participants"]').addEventListener('click', function ()
	{
		Array.prototype.forEach.call(participantResults.selectedOptions, function (option)
		{
			addOption(eventParticipants, { id: option.value, name: option.textContent, type: '' });
		});
	});
	form.querySelector('[data-action="remove-participants"]').addEventListener('click', function ()
	{
		Array.prototype.slice.call(eventParticipants.selectedOptions).forEach(function (option)
		{
			option.remove();
		});
	});

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
			else if (key.indexOf('custom_fields[') === 0)
			{
				var field = key.slice(14, -1);
				payload.custom_fields = payload.custom_fields || {};
				payload.custom_fields[field] = value;
			}
			else
			{
				payload[key] = value;
			}
		});
		payload.private = form.elements.private.checked ? 1 : 0;
		payload.owner_participates = form.elements.owner_participates.checked ? 1 : 0;
		payload.participants = selectedParticipants();

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