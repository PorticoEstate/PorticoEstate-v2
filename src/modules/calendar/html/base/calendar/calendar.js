(function ()
{
	'use strict';

	var config = window.__calendarView;
	var root = document.querySelector('.calendar-shell');
	if (!config || !root) return;

	var grid = document.getElementById('calendar-grid');
	var error = document.getElementById('calendar-error');
	var rangeLabel = document.getElementById('calendar-range-label');
	var dateInput = document.getElementById('calendar-date');
	var formatter = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' });

	function ymdToDate(value)
	{
		value = String(value || '').replace(/[^0-9]/g, '');
		if (value.length !== 8) return new Date();
		return new Date(Number(value.slice(0, 4)), Number(value.slice(4, 6)) - 1, Number(value.slice(6, 8)));
	}

	function dateToYmd(date)
	{
		return String(date.getFullYear()) + String(date.getMonth() + 1).padStart(2, '0') + String(date.getDate()).padStart(2, '0');
	}

	function dateToInput(date)
	{
		return String(date.getFullYear()) + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
	}

	function shiftDate(date, direction)
	{
		var next = new Date(date.getTime());
		if (config.view === 'day') next.setDate(next.getDate() + direction);
		else if (config.view === 'week' || config.view === 'week-new') next.setDate(next.getDate() + (direction * 7));
		else if (config.view === 'year') next.setFullYear(next.getFullYear() + direction);
		else next.setMonth(next.getMonth() + direction);
		return next;
	}

	function groupEvents(events)
	{
		return events.reduce(function (grouped, event)
		{
			(grouped[event.date] = grouped[event.date] || []).push(event);
			return grouped;
		}, {});
	}

	function renderEvents(day, events)
	{
		var list = document.createElement('div');
		list.className = 'calendar-grid__events';
		(events || []).forEach(function (event)
		{
			var link = document.createElement('a');
			link.className = 'calendar-event';
			link.href = event.view_url;
			link.textContent = (event.start_time ? event.start_time + ' ' : '') + (event.title || config.lang.private);
			link.title = event.location ? event.location : event.description || event.title;
			list.appendChild(link);
		});
		if (!events || !events.length)
		{
			var empty = document.createElement('span');
			empty.className = 'calendar-grid__empty';
			empty.textContent = config.lang.noEvents;
			list.appendChild(empty);
		}
		day.appendChild(list);
	}

	function render(payload)
	{
		var eventsByDate = groupEvents(payload.data || []);
		var start = new Date(payload.range.start + 'T00:00:00');
		var end = new Date(payload.range.end + 'T00:00:00');
		var cursor = new Date(start.getTime());
		grid.textContent = '';
		grid.className = 'calendar-grid calendar-grid--' + config.view;
		rangeLabel.textContent = formatter.format(start) + ' - ' + formatter.format(end);

		while (cursor <= end)
		{
			var ymd = dateToYmd(cursor);
			var day = document.createElement('article');
			day.className = 'calendar-grid__day';
			var heading = document.createElement('h2');
			heading.textContent = formatter.format(cursor);
			day.appendChild(heading);
			renderEvents(day, eventsByDate[ymd]);
			grid.appendChild(day);
			cursor.setDate(cursor.getDate() + 1);
		}
	}

	function load(date)
	{
		var ymd = dateToYmd(date);
		var url = new URL(config.apiUrl, window.location.origin);
		url.searchParams.set('view', config.view);
		url.searchParams.set('date', ymd);
		dateInput.value = dateToInput(date);
		error.hidden = true;
		fetch(url.toString(), {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' }
		}).then(function (response)
		{
			return response.json().then(function (payload)
			{
				if (!response.ok) throw new Error(payload.error || config.lang.loadFailed);
				return payload;
			});
		}).then(render).catch(function (err)
		{
			error.textContent = err.message || config.lang.loadFailed;
			error.hidden = false;
		});
	}

	document.querySelectorAll('[data-view-link]').forEach(function (link)
	{
		if (link.dataset.viewLink === config.view) link.setAttribute('aria-current', 'page');
	});

	root.querySelector('[data-calendar-prev]').addEventListener('click', function ()
	{
		load(shiftDate(ymdToDate(dateToYmd(new Date(dateInput.value + 'T00:00:00'))), -1));
	});

	root.querySelector('[data-calendar-next]').addEventListener('click', function ()
	{
		load(shiftDate(ymdToDate(dateToYmd(new Date(dateInput.value + 'T00:00:00'))), 1));
	});

	dateInput.addEventListener('change', function ()
	{
		load(new Date(dateInput.value + 'T00:00:00'));
	});

	load(ymdToDate(config.date));
})();