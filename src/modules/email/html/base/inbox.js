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
	const moveStatus = document.getElementById('email-inbox-move-status');
	let draggedMessage = null;
	let movePending = false;

	function canDrop(folder)
	{
		return !movePending && draggedMessage
			&& Number(draggedMessage.account) === Number(folder.account)
			&& draggedMessage.folder !== folder.longName;
	}

	function clearDropTargets()
	{
		folderList.querySelectorAll('.is-drop-target').forEach(link => link.classList.remove('is-drop-target'));
	}

	async function moveMessage(message, folder)
	{
		if (movePending) return;
		movePending = true;
		root.setAttribute('aria-busy', 'true');
		moveStatus.textContent = 'Moving message';
		error.hidden = true;
		try
		{
			const response = await fetch(root.dataset.moveUrl, {
				method: 'POST',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-CSRF-NAME': root.dataset.csrfName,
					'X-CSRF-VALUE': root.dataset.csrfValue
				},
				body: JSON.stringify({
					msgball: { acctnum: message.account, folder: message.folder, msgnum: message.messageNumber },
					target_fldball: { acctnum: folder.account, folder: folder.longName }
				})
			});
			const result = await response.json();
			if (!response.ok || !result.moved) throw new Error(result.reason || result.error || 'Unable to move message');
			await load();
			await loadFolders();
			moveStatus.textContent = 'Message moved to ' + folder.longName;
		} catch (exception)
		{
			moveStatus.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
		} finally
		{
			movePending = false;
			root.setAttribute('aria-busy', 'false');
		}
	}

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
				link.addEventListener('dragover', event =>
				{
					if (!canDrop(folder)) return;
					event.preventDefault();
					event.dataTransfer.dropEffect = 'move';
					link.classList.add('is-drop-target');
				});
				link.addEventListener('dragleave', event =>
				{
					if (!link.contains(event.relatedTarget)) link.classList.remove('is-drop-target');
				});
				link.addEventListener('drop', event =>
				{
					event.preventDefault();
					clearDropTargets();
					if (!canDrop(folder)) return;
					const message = draggedMessage;
					draggedMessage = null;
					moveMessage(message, folder);
				});
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
			row.draggable = Boolean(item.sourceFolder);
			row.classList.add('email-inbox-draggable');
			row.addEventListener('dragstart', event =>
			{
				if (movePending || !row.draggable)
				{
					event.preventDefault();
					return;
				}
				draggedMessage = { account: item.account, folder: item.sourceFolder, messageNumber: item.messageNumber };
				event.dataTransfer.clearData();
				event.dataTransfer.setData('application/x-portico-message', String(item.messageNumber));
				event.dataTransfer.effectAllowed = 'move';
				row.classList.add('is-dragging');
			});
			row.addEventListener('dragend', () =>
			{
				draggedMessage = null;
				row.classList.remove('is-dragging');
				clearDropTargets();
			});
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
			if (!shown && start > 0)
			{
				const destination = new URL(window.location.href);
				const lastPage = total > 0 ? Math.floor((total - 1) / pageSize) * pageSize : 0;
				if (Number(destination.searchParams.get('start') || 0) !== lastPage)
				{
					if (lastPage > 0) destination.searchParams.set('start', String(lastPage));
					else destination.searchParams.delete('start');
					window.history.replaceState(null, '', destination);
					return load();
				}
			}
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
