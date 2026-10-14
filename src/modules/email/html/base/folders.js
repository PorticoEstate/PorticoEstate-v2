(function ()
{
	const root = document.getElementById('email-folders');
	if (!root) return;

	const rows = document.getElementById('email-folders-rows');
	const status = document.getElementById('email-folders-status');
	const error = document.getElementById('email-folders-error');
	const form = document.getElementById('email-folders-manager');
	const actionSelect = document.getElementById('email-folder-action');
	const sourceSelect = document.getElementById('email-folder-source');
	const targetInput = document.getElementById('email-folder-target');
	const actionStatus = document.getElementById('email-folders-action-status');
	let folders = [];

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
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to load folders');
			rows.replaceChildren();
			folders = result.items || [];
			sourceSelect.replaceChildren();
			folders.forEach(folder =>
			{
				const option = document.createElement('option');
				option.value = folder.longName;
				option.textContent = folder.name || folder.longName;
				sourceSelect.appendChild(option);
			});
			updateActionFields();
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

	function updateActionFields()
	{
		const needsSource = actionSelect.value === 'rename';
		sourceSelect.disabled = !needsSource;
		sourceSelect.required = needsSource;
		targetInput.required = actionSelect.value !== 'delete';
		if (actionSelect.value === 'delete')
		{
			targetInput.value = sourceSelect.selectedOptions[0]?.textContent || '';
			targetInput.readOnly = true;
		} else
		{
			targetInput.readOnly = false;
		}
	}

	actionSelect.addEventListener('change', updateActionFields);
	form.addEventListener('submit', async event =>
	{
		event.preventDefault();
		const action = actionSelect.value;
		if (action === 'delete' && !window.confirm('Delete folder "' + targetInput.value + '"?')) return;
		actionStatus.textContent = '';
		error.hidden = true;
		const account = Number(root.dataset.account || 0);
		const targetFolder = action === 'delete' ? sourceSelect.value : targetInput.value.trim();
		const payload = {
			action,
			target_fldball: { acctnum: account, folder: targetFolder }
		};
		if (action === 'rename') payload.source_fldball = { acctnum: account, folder: sourceSelect.value };
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
				body: JSON.stringify(payload)
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to update folder');
			actionStatus.textContent = result.message || 'Folder updated';
			targetInput.value = '';
			await load();
		} catch (exception)
		{
			error.textContent = exception.message;
			error.hidden = false;
		}
	});

	load();
})();
