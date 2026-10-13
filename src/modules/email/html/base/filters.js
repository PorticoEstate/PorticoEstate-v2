(function ()
{
	const root = document.getElementById('email-filters');
	if (!root) return;

	const rows = document.getElementById('email-filters-rows');
	const status = document.getElementById('email-filters-status');
	const error = document.getElementById('email-filters-error');

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

	async function load()
	{
		try
		{
			const response = await fetch(root.dataset.apiUrl, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to load filters');
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
					addLink(actions, 'Move up', filter.moveUpUrl);
					addLink(actions, 'Move down', filter.moveDownUrl);
					addLink(actions, 'Delete', filter.deleteUrl, 'Delete filter "' + filter.name + '"?');
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

	load();
})();
