(function ()
{
	const root = document.getElementById('email-filter-edit');
	if (!root) return;

	const form = document.getElementById('email-filter-edit-form');
	const status = document.getElementById('email-filter-edit-status');
	const error = document.getElementById('email-filter-edit-error');
	const report = document.getElementById('email-filter-edit-report');
	const sourceAccounts = document.getElementById('email-filter-source-accounts');
	const conditions = document.getElementById('email-filter-condition-list');
	const actionAccount = form.elements.actionAccount;
	const actionFolder = form.elements.actionFolder;
	let filterId = 'add_new';

	function option(select, value, label)
	{
		const item = document.createElement('option');
		item.value = value;
		item.textContent = label;
		select.appendChild(item);
		return item;
	}

	function selectControl(name, values, selected)
	{
		const select = document.createElement('select');
		select.name = name;
		values.forEach(([value, label]) =>
		{
			const item = option(select, value, label);
			if (value === selected) item.selected = true;
		});
		return select;
	}

	function renderCondition(match, index)
	{
		const row = document.createElement('div');
		row.className = 'email-filter-condition';
		if (index > 0)
		{
			row.appendChild(selectControl('match_' + index + '_andor', [
				['ignore_me', 'Not used'], ['and', 'And'], ['or', 'Or']
			], match.andor || 'ignore_me'));
		}
		row.appendChild(selectControl('match_' + index + '_examine', [
			['from', 'From'], ['to', 'To'], ['cc', 'CC'], ['recipient', 'Recipient'],
			['sender', 'Sender'], ['subject', 'Subject'], ['received', 'Received headers']
		], match.examine || 'from'));
		row.appendChild(selectControl('match_' + index + '_comparator', [
			['contains', 'Contains'], ['notcontains', 'Does not contain']
		], match.comparator || 'contains'));
		const text = document.createElement('input');
		text.type = 'text';
		text.name = 'match_' + index + '_matchthis';
		text.value = match.matchthis || '';
		text.required = index === 0;
		text.maxLength = 500;
		text.setAttribute('aria-label', 'Condition text ' + (index + 1));
		row.appendChild(text);
		conditions.appendChild(row);
	}

	async function loadFolders(account, selectedFolder)
	{
		actionFolder.replaceChildren();
		const url = new URL(root.dataset.foldersApiUrl, window.location.href);
		url.searchParams.set('fldball[acctnum]', String(account));
		const response = await fetch(url, { headers: { Accept: 'application/json' } });
		const result = await response.json();
		if (!response.ok) throw new Error(result.reason || result.error || 'Unable to load folders');
		(result.items || []).forEach(folder =>
		{
			const item = option(actionFolder, folder.longName, folder.name || folder.longName);
			if (folder.longName === selectedFolder) item.selected = true;
		});
	}

	async function load()
	{
		try
		{
			const response = await fetch(root.dataset.apiUrl, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to load filter');
			const filter = result.filter;
			filterId = filter.id;
			root.querySelectorAll('[data-filter-run]').forEach(button =>
			{
				button.disabled = filterId === 'add_new';
			});
			form.elements.name.value = filter.name;
			(result.accounts || []).forEach(account =>
			{
				const checkbox = document.createElement('input');
				checkbox.type = 'checkbox';
				checkbox.name = 'sourceAccounts';
				checkbox.value = String(account.id);
				checkbox.checked = filter.sourceAccounts.includes(account.id);
				const label = document.createElement('label');
				label.append(checkbox, document.createTextNode(account.label));
				sourceAccounts.appendChild(label);
				option(actionAccount, String(account.id), account.label);
			});
			actionAccount.value = String(filter.action.account);
			filter.matches.slice(0, 3).forEach(renderCondition);
			form.elements.actiontext.value = filter.action.actiontext;
			form.elements.stopFiltering.checked = filter.action.stopFiltering;
			await loadFolders(filter.action.account, filter.action.folder);
			form.hidden = false;
			status.textContent = '';
		} catch (exception)
		{
			status.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
		}
	}

	actionAccount.addEventListener('change', () => loadFolders(actionAccount.value).catch(exception =>
	{
		error.textContent = exception.message;
		error.hidden = false;
	}));

	form.addEventListener('submit', async event =>
	{
		event.preventDefault();
		error.hidden = true;
		const selectedSources = [...sourceAccounts.querySelectorAll('input:checked')].map(input => Number(input.value));
		const matchRows = [...conditions.querySelectorAll('.email-filter-condition')];
		const matches = matchRows.map((row, index) => ({
			andor: index === 0 ? '' : row.querySelector('select').value,
			examine: row.querySelectorAll('select')[index === 0 ? 0 : 1].value,
			comparator: row.querySelectorAll('select')[index === 0 ? 1 : 2].value,
			matchthis: row.querySelector('input').value
		}));
		if (!selectedSources.length)
		{
			error.textContent = 'Select at least one account.';
			error.hidden = false;
			return;
		}
		const payload = {
			id: filterId,
			name: form.elements.name.value,
			sourceAccounts: selectedSources,
			matches,
			action: {
				judgement: 'fileinto',
				account: Number(actionAccount.value),
				folder: actionFolder.value,
				actiontext: form.elements.actiontext.value,
				stopFiltering: form.elements.stopFiltering.checked
			}
		};
		for (let index = 1; index < matches.length; index++)
		{
			if (!matches[index].matchthis.trim()) matches[index].andor = 'ignore_me';
		}
		try
		{
			const response = await fetch(root.dataset.saveUrl, {
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
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to save filter');
			window.location.assign(root.dataset.filtersUrl);
		} catch (exception)
		{
			error.textContent = exception.message;
			error.hidden = false;
		}
	});

	root.querySelectorAll('[data-filter-run]').forEach(button =>
	{
		button.addEventListener('click', async () =>
		{
			const test = button.dataset.filterRun === 'test';
			if (!test && !window.confirm('Apply this saved filter now?')) return;
			button.disabled = true;
			error.hidden = true;
			report.replaceChildren();
			report.hidden = true;
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
					body: JSON.stringify({ filterNum: Number(filterId), test })
				});
				const result = await response.json();
				if (!response.ok) throw new Error(result.reason || result.error || 'Unable to run filter');
				const output = document.createElement('pre');
				output.textContent = test ? result.matchReport : (result.items || []).map(item => item.name + ': ' + item.matchCount + ' matches').join('\n');
				report.appendChild(output);
				report.hidden = false;
			} catch (exception)
			{
				error.textContent = exception.message;
				error.hidden = false;
			} finally
			{
				button.disabled = false;
			}
		});
	});

	load();
})();