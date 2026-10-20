(function ()
{
	const root = document.getElementById('email-search');
	if (!root) return;

	const form = document.getElementById('email-search-form');
	const folders = document.getElementById('email-search-folders');
	const rows = document.getElementById('email-search-rows');
	const status = document.getElementById('email-search-status');
	const error = document.getElementById('email-search-error');
	const pagination = document.getElementById('email-search-pagination');
	const range = document.getElementById('email-search-range');
	const previous = document.getElementById('email-search-previous');
	const next = document.getElementById('email-search-next');
	const csrfHeaders = {
		'X-CSRF-NAME': root.dataset.csrfName,
		'X-CSRF-VALUE': root.dataset.csrfValue
	};
	let pageStart = 0;
	let pageSize = 25;
	let total = 0;

	function cell(row, value)
	{
		const element = document.createElement('td');
		element.textContent = value == null ? '' : String(value);
		row.appendChild(element);
		return element;
	}

	function render(items)
	{
		rows.replaceChildren();
		if (!items.length)
		{
			const row = document.createElement('tr');
			const empty = cell(row, 'No messages found');
			empty.colSpan = 5;
			rows.appendChild(row);
			return;
		}
		items.forEach(item =>
		{
			const row = document.createElement('tr');
			cell(row, item.folderName);
			cell(row, item.from);
			const subject = document.createElement('td');
			const link = document.createElement('a');
			link.href = item.messageUrl;
			link.textContent = item.subject || '(no subject)';
			subject.appendChild(link);
			row.appendChild(subject);
			cell(row, item.date);
			cell(row, item.size);
			rows.appendChild(row);
		});
	}

	async function loadFolders()
	{
		const response = await fetch(root.dataset.foldersApiUrl, { headers: { Accept: 'application/json' } });
		const result = await response.json();
		if (!response.ok) throw new Error(result.error || 'Unable to load folders');
		const preferred = root.dataset.preferredFolder;
		(result.items || []).forEach(folder =>
		{
			const option = new Option(folder.name || folder.longName, folder.name || folder.longName);
			option.selected = option.value === preferred || folder.longName === preferred;
			folders.add(option);
		});
	}

	async function search()
	{
		error.hidden = true;
		status.textContent = 'Searching';
		const data = new FormData(form);
		const payload = {
			fldball: { acctnum: Number(root.dataset.account) || 0 },
			folder_list: Array.from(folders.selectedOptions, option => option.value),
			flags: Array.from(form.querySelectorAll('[name="flags"]:checked'), input => input.value),
			start: pageStart
		};
		['search_subject', 'search_body', 'search_from', 'search_to', 'search_cc', 'search_bcc', 'search_keyword', 'date_on', 'date_before', 'date_after'].forEach(key =>
		{
			payload[key] = data.get(key) || '';
		});
		try
		{
			const response = await fetch(root.dataset.apiUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders },
				body: JSON.stringify(payload)
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to search messages');
			total = Number(result.total) || 0;
			pageStart = Number(result.start) || 0;
			pageSize = Math.max(1, Number(result.pageSize) || 25);
			status.textContent = total + ' results';
			render(result.items || []);
			pagination.hidden = total <= pageSize;
			previous.disabled = pageStart <= 0;
			next.disabled = pageStart + (result.items || []).length >= total;
			range.textContent = total ? (pageStart + 1) + '–' + Math.min(pageStart + (result.items || []).length, total) + ' of ' + total : '0 results';
		} catch (exception)
		{
			status.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
			render([]);
		}
	}

	form.addEventListener('submit', event =>
	{
		event.preventDefault();
		pageStart = 0;
		search();
	});
	previous.addEventListener('click', () =>
	{
		pageStart = Math.max(0, pageStart - pageSize);
		search();
	});
	next.addEventListener('click', () =>
	{
		pageStart += pageSize;
		search();
	});

	loadFolders().catch(exception =>
	{
		status.textContent = '';
		error.textContent = exception.message;
		error.hidden = false;
	});
})();
