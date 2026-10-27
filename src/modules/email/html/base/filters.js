(function ()
{
	const root = document.getElementById('email-filters');
	if (!root) return;

	const rows = document.getElementById('email-filters-rows');
	const status = document.getElementById('email-filters-status');
	const error = document.getElementById('email-filters-error');
	const report = document.getElementById('email-filters-report');

	function addCell(row, value)
	{
		const cell = document.createElement('td');
		cell.textContent = value == null ? '' : String(value);
		row.appendChild(cell);
		return cell;
	}

	function addLink(container, label, url, confirmMessage)
	{
		if (!url) return;
		const link = document.createElement('a');
		link.href = url;
		link.textContent = label;
		if (confirmMessage)
		{
			link.addEventListener('click', event =>
			{
				if (!window.confirm(confirmMessage)) event.preventDefault();
			});
		}
		container.appendChild(link);
	}

	function addActionButton(container, label, action, filter)
	{
		const button = document.createElement('button');
		button.type = 'button';
		button.textContent = label;
		button.addEventListener('click', async () =>
		{
			if (action === 'delete' && !window.confirm('Delete filter "' + filter.name + '"?')) return;
			button.disabled = true;
			try
			{
				const response = await fetch(root.dataset.actionUrl, {
					method: 'POST',
					headers: {
						Accept: 'application/json',
						'Content-Type': 'application/json',
						'X-CSRF-NAME': root.dataset.csrfName,
						'X-CSRF-VALUE': root.dataset.csrfValue
					},
					body: JSON.stringify({ action, filterNum: filter.id })
				});
				const result = await response.json();
				if (!response.ok) throw new Error(result.reason || result.error || 'Unable to update filter');
				await load();
			} catch (exception)
			{
				status.textContent = '';
				error.textContent = exception.message;
				error.hidden = false;
				button.disabled = false;
			}
		});
		container.appendChild(button);
	}

	async function load()
	{
		try
		{
			const response = await fetch(root.dataset.apiUrl, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to load filters');
			const filters = result.items || [];
			rows.replaceChildren();
			if (!filters.length)
			{
				const row = document.createElement('tr');
				const empty = addCell(row, 'No filters configured');
				empty.colSpan = 5;
				rows.appendChild(row);
			} else
			{
				filters.forEach(filter =>
				{
					const row = document.createElement('tr');
					addCell(row, filter.name);
					addCell(row, filter.sourceCount);
					addCell(row, filter.matchCount);
					addCell(row, filter.actionCount);
					const actions = addCell(row, '');
					actions.className = 'email-filters-actions';
					addLink(actions, 'Edit', filter.editUrl);
					addActionButton(actions, 'Move up', 'move-up', filter);
					addActionButton(actions, 'Move down', 'move-down', filter);
					addActionButton(actions, 'Delete', 'delete', filter);
					rows.appendChild(row);
				});
			}
			status.textContent = filters.length + ' filters';
		} catch (exception)
		{
			status.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
		}
	}

	async function runFilters(test)
	{
		if (!test && !window.confirm('Apply all email filters now?')) return;
		const button = root.querySelector('[data-filter-run="' + (test ? 'test' : 'apply') + '"]');
		button.disabled = true;
		error.hidden = true;
		report.replaceChildren();
		report.hidden = true;
		status.textContent = test ? 'Testing filters…' : 'Applying filters…';
		try
		{
			const response = await fetch(root.dataset.runUrl, {
				method: 'POST',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-CSRF-NAME': root.dataset.csrfName,
					'X-CSRF-VALUE': root.dataset.csrfValue
				},
				body: JSON.stringify({ test })
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to run filters');
			if (test && result.matchReport)
			{
				const pre = document.createElement('pre');
				pre.textContent = result.matchReport;
				report.appendChild(pre);
			} else
			{
				const list = document.createElement('ul');
				(result.items || []).forEach(item =>
				{
					const entry = document.createElement('li');
					entry.textContent = item.name + ': ' + item.matchCount + ' matches';
					list.appendChild(entry);
				});
				report.appendChild(list);
			}
			report.hidden = false;
			status.textContent = test ? 'Filter test complete' : 'Filters applied';
		} catch (exception)
		{
			status.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
		} finally
		{
			button.disabled = false;
		}
	}

	root.querySelectorAll('[data-filter-run]').forEach(button =>
	{
		button.addEventListener('click', () => runFilters(button.dataset.filterRun === 'test'));
	});

	load();
})();
