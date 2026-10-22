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
	const sourceField = document.getElementById('email-folder-source-field');
	const sourceLabel = document.getElementById('email-folder-source-label');
	const parentSelect = document.getElementById('email-folder-parent');
	const parentField = document.getElementById('email-folder-parent-field');
	const submitButton = document.getElementById('email-folder-submit');
	const targetInput = document.getElementById('email-folder-target');
	const actionStatus = document.getElementById('email-folders-action-status');
	let folders = [];
	let delimiter = '';

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
			delimiter = result.delimiter || '';
			folders = window.emailFolderHierarchy(result.items || [], delimiter);
			const selectedParent = parentSelect.value;
			parentSelect.querySelectorAll('option:not(:first-child)').forEach(option => option.remove());
			sourceSelect.replaceChildren();
			folders.forEach(folder =>
			{
				const option = document.createElement('option');
				option.value = folder.longName;
				option.textContent = '\u00a0\u00a0'.repeat(folder.depth) + folder.label;
				sourceSelect.appendChild(option);
				if (delimiter)
				{
					const parentOption = document.createElement('option');
					parentOption.value = folder.longName;
					parentOption.textContent = '\u00a0\u00a0'.repeat(folder.depth) + folder.label;
					parentSelect.appendChild(parentOption);
				}
			});
			parentSelect.value = [...parentSelect.options].some(option => option.value === selectedParent) ? selectedParent : '';
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
					name.className = 'email-folders-name';
					name.style.setProperty('--folder-depth', folder.depth);
					const link = document.createElement('a');
					link.href = window.emailLink(root.dataset.inboxUrl, {
						'fldball[acctnum]': folder.account, 'fldball[folder]': folder.longName
					});
					link.textContent = folder.label;
					link.title = folder.longName;
					name.appendChild(link);
					row.appendChild(name);
					cell(row, folder.unseen);
					cell(row, folder.total);
					rows.appendChild(row);
				});
			}
			status.textContent = folders.length + ' folders';
			submitButton.disabled = false;
		} catch (exception)
		{
			status.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
		}
	}

	function updateActionFields()
	{
		const needsSource = actionSelect.value !== 'create';
		sourceField.hidden = !needsSource;
		sourceLabel.textContent = actionSelect.value === 'delete' ? 'Folder to delete' : 'Folder to rename';
		sourceSelect.disabled = !needsSource;
		sourceSelect.required = needsSource;
		parentField.hidden = needsSource;
		parentSelect.disabled = needsSource || !delimiter;
		targetInput.required = actionSelect.value !== 'delete';
		if (actionSelect.value === 'delete')
		{
			targetInput.value = folders.find(folder => folder.longName === sourceSelect.value)?.longName || '';
			targetInput.readOnly = true;
		} else
		{
			targetInput.readOnly = false;
		}
	}

	actionSelect.addEventListener('change', updateActionFields);
	sourceSelect.addEventListener('change', updateActionFields);
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
		if (action === 'create') payload.parent_folder = parentSelect.value;
		submitButton.disabled = true;
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
			submitButton.disabled = false;
		}
	});

	load();
})();
