(function ()
{
	const root = document.getElementById('email-folders');
	if (!root) return;

	const rows = document.getElementById('email-folders-rows');
	const status = document.getElementById('email-folders-status');
	const error = document.getElementById('email-folders-error');

	function cell(row, value)
	{
		const element = document.createElement('td');
		element.textContent = value == null ? '' : String(value);
		row.appendChild(element);
		return element;
	}

	async function load()
	{
		try
		{
			const response = await fetch(root.dataset.apiUrl, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to load folders');
			rows.replaceChildren();
			const folders = result.items || [];
			if (!folders.length)
			{
				const row = document.createElement('tr');
				const empty = cell(row, 'No folders found');
				empty.colSpan = 3;
				rows.appendChild(row);
			} else
			{
				folders.forEach(folder =>
				{
					const row = document.createElement('tr');
					const name = document.createElement('td');
					const link = document.createElement('a');
					const inboxUrl = new URL('/email/view/inbox', window.location.origin);
					inboxUrl.searchParams.set('fldball[acctnum]', String(folder.account));
					inboxUrl.searchParams.set('fldball[folder]', folder.folderParam || folder.longName);
					link.href = inboxUrl;
					link.textContent = folder.name || folder.longName;
					name.appendChild(link);
					row.appendChild(name);
					cell(row, folder.unseen);
					cell(row, folder.total);
					rows.appendChild(row);
				});
			}
			status.textContent = folders.length + ' folders';
		} catch (exception)
		{
			status.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
		}
	}

	load();
})();
