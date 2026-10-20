(function ()
{
	const root = document.getElementById('email-inbox');
	if (!root || root.dataset.hasPreferences !== '1') return;

	const rows = document.getElementById('email-inbox-rows');
	const status = document.getElementById('email-inbox-status');
	const error = document.getElementById('email-inbox-error');
	const pagination = document.getElementById('email-inbox-pagination');
	const range = document.getElementById('email-inbox-range');
	const previous = document.getElementById('email-inbox-previous');
	const next = document.getElementById('email-inbox-next');
	const folderList = document.getElementById('email-inbox-folder-list');
	const folderStatus = document.getElementById('email-inbox-folder-status');
	const folderError = document.getElementById('email-inbox-folder-error');
	const heading = document.getElementById('email-inbox-title');
	const selection = new URL(window.location.href).searchParams;
	const activeAccount = Number(selection.get('fldball[acctnum]') || 0);
	const activeFolder = selection.get('fldball[folder]') || 'INBOX';

	async function loadFolders()
	{
		try
		{
			const url = new URL(root.dataset.foldersApiUrl, window.location.origin);
			url.searchParams.set('fldball[acctnum]', String(activeAccount));
			const response = await fetch(url, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to load folders');
			const folders = window.emailFolderHierarchy(result.items || [], result.delimiter || '');
			folderList.replaceChildren();
			const lists = [folderList];
			folders.forEach(folder =>
			{
				while (lists.length > folder.depth + 1) lists.pop();
				if (folder.depth === lists.length)
				{
					const children = document.createElement('ul');
					lists[lists.length - 1].lastElementChild.appendChild(children);
					lists.push(children);
				}
				const item = document.createElement('li');
				const link = document.createElement('a');
				const destination = new URL(window.location.href);
				destination.searchParams.set('fldball[acctnum]', String(folder.account));
				destination.searchParams.set('fldball[folder]', folder.longName);
				destination.searchParams.delete('start');
				destination.searchParams.delete('fldball_fake_uri');
				link.href = destination;
				const total = Math.max(0, Number(folder.total) || 0);
				const unread = Math.max(0, Number(folder.unseen) || 0);
				link.title = folder.longName + ': ' + total + ' messages, ' + unread + ' unread';
				link.setAttribute('aria-label', link.title);
				if (unread) link.classList.add('has-unread');
				if (Number(folder.account) === activeAccount && folder.longName === activeFolder)
				{
					link.setAttribute('aria-current', 'page');
					heading.textContent = folder.label;
				}
				const label = document.createElement('span');
				label.className = 'email-inbox-folder-name';
				label.textContent = folder.label;
				const count = document.createElement('span');
				count.className = 'email-inbox-folder-count';
				count.textContent = String(total);
				count.setAttribute('aria-hidden', 'true');
				link.append(label, count);
				item.appendChild(link);
				lists[lists.length - 1].appendChild(item);
			});
			folderStatus.textContent = folders.length ? '' : 'No folders found';
			folderStatus.hidden = folders.length > 0;
			folderError.hidden = true;
		} catch (exception)
		{
			folderStatus.hidden = true;
			folderError.textContent = exception.message;
			folderError.hidden = false;
		}
	}

	function cell(row, value, className)
	{
		const element = document.createElement('td');
		element.textContent = value == null ? '' : String(value);
		if (className) element.className = className;
		row.appendChild(element);
		return element;
	}

	function setPage(start)
	{
		const url = new URL(window.location.href);
		if (start > 0) url.searchParams.set('start', String(start));
		else url.searchParams.delete('start');
		window.location.assign(url);
	}

	function renderItems(items, errorText)
	{
		rows.replaceChildren();
		if (!items.length)
		{
			const row = document.createElement('tr');
			const empty = cell(row, errorText || 'No messages in this folder');
			empty.colSpan = 4;
			rows.appendChild(row);
			return;
		}

		items.forEach(item =>
		{
			const row = document.createElement('tr');
			if (item.unseen) row.classList.add('is-unseen');

			const sender = cell(row, item.from);
			if (item.fromAddress)
			{
				const address = document.createElement('span');
				address.className = 'email-inbox-sender-address';
				address.textContent = item.fromAddress;
				sender.appendChild(address);
			}

			const subjectCell = document.createElement('td');
			subjectCell.className = 'email-inbox-subject';
			if (item.messageUrl)
			{
				const link = document.createElement('a');
				link.href = item.messageUrl;
				link.textContent = item.subject || '(no subject)';
				subjectCell.appendChild(link);
			} else
			{
				subjectCell.textContent = item.subject || '(no subject)';
			}
			if (item.hasAttachments)
			{
				const attachment = document.createElement('span');
				attachment.className = 'email-inbox-attachment';
				attachment.textContent = ' Attachment';
				subjectCell.appendChild(attachment);
			}
			row.appendChild(subjectCell);
			cell(row, item.date);
			cell(row, item.size);
			rows.appendChild(row);
		});
	}

	async function load()
	{
		try
		{
			const url = new URL(root.dataset.apiUrl, window.location.origin);
			url.search = window.location.search;
			const response = await fetch(url, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to load messages');

			error.hidden = true;
			status.textContent = [result.folder, result.unseen + ' unread', result.total + ' messages'].filter(Boolean).join(' · ');
			renderItems(result.items || [], result.error);

			const start = Math.max(0, Number(result.start) || 0);
			const total = Math.max(0, Number(result.total) || 0);
			const pageSize = Math.max(1, Number(result.pageSize) || (result.items || []).length || 25);
			const shown = (result.items || []).length;
			pagination.hidden = total <= pageSize;
			previous.disabled = start <= 0;
			next.disabled = start + shown >= total;
			range.textContent = shown ? (start + 1) + '–' + (start + shown) + ' of ' + total : '0 of ' + total;
			previous.onclick = () => setPage(Math.max(0, start - pageSize));
			next.onclick = () => setPage(start + pageSize);
		} catch (exception)
		{
			status.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
			renderItems([], '');
		}
	}

	load();
	loadFolders();
})();
