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
	let seenPending = false;
	const selected = new Map();
	const selectAll = document.getElementById('email-inbox-select-all');
	const selectedCount = document.getElementById('email-inbox-selected');
	const targetAccount = document.getElementById('email-inbox-target-account');
	const targetFolder = document.getElementById('email-inbox-target-folder');
	const moveSelected = document.getElementById('email-inbox-move-selected');
	const deleteSelected = document.getElementById('email-inbox-delete-selected');
	const emptyTrash = document.getElementById('email-inbox-empty-trash');
	let pageItems = [];
	let trashFolder = '';
	let destinationsLoading = false;
	let currentSort = String(selection.get('sort') || '1');
	let currentOrder = String(selection.get('order') || '0');
	root.querySelectorAll('[data-email-sort]').forEach(button =>
	{
		button.addEventListener('click', () =>
		{
			if (movePending || seenPending) return;
			window.location.assign(window.emailLink(window.location.href, {
				sort: button.dataset.emailSort,
				order: currentSort === button.dataset.emailSort && currentOrder !== '1' ? '1' : '0',
				start: null
			}));
		});
	});
	function updateSortIndicators()
	{
		root.querySelectorAll('[data-email-sort]').forEach(button =>
		{
			button.closest('th').setAttribute('aria-sort', currentSort === button.dataset.emailSort ? (currentOrder === '1' ? 'descending' : 'ascending') : 'none');
		});
	}
	updateSortIndicators();

	function updateSelection()
	{
		selectedCount.textContent = selected.size + ' selected';
		const available = pageItems.filter(item => item.sourceFolder);
		selectAll.checked = available.length > 0 && selected.size === available.length;
		selectAll.indeterminate = selected.size > 0 && selected.size < available.length;
		moveSelected.disabled = !selected.size || movePending || seenPending || destinationsLoading || !targetFolder.value;
		deleteSelected.disabled = !selected.size || movePending || seenPending;
		emptyTrash.disabled = !trashFolder || movePending || seenPending;
		selectAll.disabled = movePending || seenPending || !available.length;
	}

	function fillDestinations(result)
	{
		const previousFolder = targetFolder.value;
		targetFolder.replaceChildren();
		window.emailFolderHierarchy(result.items || [], result.delimiter || '').forEach(folder =>
		{
			const option = new Option('\u00a0\u00a0'.repeat(folder.depth) + folder.label, folder.longName);
			option.title = folder.longName;
			targetFolder.add(option);
		});
		if ([...targetFolder.options].some(option => option.value === previousFolder)) targetFolder.value = previousFolder;
		updateSelection();
	}

	targetAccount.addEventListener('change', async () =>
	{
		destinationsLoading = true;
		targetFolder.replaceChildren();
		updateSelection();
		const account = targetAccount.value;
		try
		{
			const url = window.emailLink(root.dataset.foldersApiUrl, { 'fldball[acctnum]': account });
			const response = await fetch(url, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to load destination folders');
			if (targetAccount.value === account) fillDestinations(result);
		} catch (exception)
		{
			error.textContent = exception.message;
			error.hidden = false;
		} finally
		{
			if (targetAccount.value === account) destinationsLoading = false;
			updateSelection();
		}
	});
	targetFolder.addEventListener('change', updateSelection);
	selectAll.addEventListener('change', () =>
	{
		const checked = selectAll.checked;
		selected.clear();
		rows.querySelectorAll('[data-message-select]').forEach(input =>
		{
			if (input.disabled) return;
			input.checked = checked;
			if (checked) selected.set(input.dataset.messageSelect, pageItems[Number(input.dataset.messageSelect)]);
		});
		updateSelection();
	});

	async function batchAction(action)
	{
		if (movePending || seenPending || (action !== 'emptyTrash' && !selected.size)) return;
		if (action === 'move' && (destinationsLoading || !targetFolder.value)) return;
		if (action === 'emptyTrash' && !trashFolder) return;
		if (action !== 'move' && !window.confirm(action === 'emptyTrash'
			? 'Permanently delete every message in Trash?'
			: 'Delete selected messages? Messages already in Trash, or accounts without Trash, are permanently deleted.')) return;
		const payload = { action, account: activeAccount, confirmed: action !== 'move', messages: [...selected.values()].map(item => ({ acctnum: item.account, folder: item.sourceFolder, msgnum: item.messageNumber })) };
		if (action === 'move') payload.target_fldball = { acctnum: Number(targetAccount.value), folder: targetFolder.value };
		movePending = true;
		updateSelection();
		error.hidden = true;
		moveStatus.textContent = 'Updating messages';
		try
		{
			const result = await window.emailMessageAction(root, payload);
			await load();
			await loadFolders();
			moveStatus.textContent = result.completed.length + ' completed, ' + result.failed.length + ' failed';
			if (result.failed.length)
			{
				error.textContent = result.failed.map(item => item.message.msgnum + ': ' + item.error).join('; ');
				error.hidden = false;
			}
		} catch (exception)
		{
			moveStatus.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
		} finally
		{
			movePending = false;
			updateSelection();
		}
	}
	moveSelected.addEventListener('click', () => batchAction('move'));
	deleteSelected.addEventListener('click', () => batchAction('delete'));
	emptyTrash.addEventListener('click', () => batchAction('emptyTrash'));

	function canDrop(folder)
	{
		return !movePending && !seenPending && draggedMessage
			&& Number(draggedMessage.account) === Number(folder.account)
			&& draggedMessage.folder !== folder.longName;
	}

	function clearDropTargets()
	{
		folderList.querySelectorAll('.is-drop-target').forEach(link => link.classList.remove('is-drop-target'));
	}

	async function moveMessage(message, folder)
	{
		if (movePending || seenPending) return;
		movePending = true;
		root.setAttribute('aria-busy', 'true');
		updateSelection();
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
			updateSelection();
		}
	}

	async function loadFolders()
	{
		try
		{
			const url = window.emailLink(root.dataset.foldersApiUrl, { 'fldball[acctnum]': activeAccount });
			const response = await fetch(url, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to load folders');
			const folders = window.emailFolderHierarchy(result.items || [], result.delimiter || '');
			trashFolder = result.trashFolder || '';
			if (!targetAccount.options.length)
			{
				(result.accounts?.length ? result.accounts : [{ id: activeAccount, name: 'Account ' + activeAccount }]).forEach(account => targetAccount.add(new Option(account.name, String(account.id))));
				targetAccount.value = String(activeAccount);
			}
			if (Number(targetAccount.value) === activeAccount) fillDestinations(result);
			updateSelection();
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
				link.href = window.emailLink(window.location.href, {
					'fldball[acctnum]': folder.account, 'fldball[folder]': folder.longName,
					start: null, fldball_fake_uri: null
				});
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
		window.location.assign(window.emailLink(window.location.href, { start: start > 0 ? start : null }));
	}

	function renderItems(items, errorText)
	{
		pageItems = items;
		selected.clear();
		rows.replaceChildren();
		updateSelection();
		if (!items.length)
		{
			const row = document.createElement('tr');
			const empty = cell(row, errorText || 'No messages in this folder');
			empty.colSpan = 6;
			rows.appendChild(row);
			return;
		}

		items.forEach((item, index) =>
		{
			const row = document.createElement('tr');
			const selectionCell = document.createElement('td');
			const selectionInput = document.createElement('input');
			selectionInput.type = 'checkbox';
			selectionInput.dataset.messageSelect = String(index);
			selectionInput.disabled = !item.sourceFolder;
			selectionInput.setAttribute('aria-label', 'Select ' + (item.subject || '(no subject)'));
			selectionInput.addEventListener('change', () =>
			{
				if (selectionInput.checked) selected.set(String(index), item);
				else selected.delete(String(index));
				updateSelection();
			});
			selectionCell.appendChild(selectionInput);
			row.appendChild(selectionCell);
			row.draggable = Boolean(item.sourceFolder);
			row.classList.add('email-inbox-draggable');
			row.addEventListener('dragstart', event =>
			{
				if (movePending || seenPending || !row.draggable)
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
			const readCell = document.createElement('td');
			readCell.className = 'email-inbox-read';
			const read = document.createElement('input');
			read.type = 'checkbox';
			read.checked = !item.unseen;
			read.disabled = !item.sourceFolder;
			function labelReadControl()
			{
				read.title = read.checked ? 'Mark as unread' : 'Mark as read';
				read.setAttribute('aria-label', read.title + ': ' + (item.subject || '(no subject)'));
			}
			labelReadControl();
			read.addEventListener('change', async () =>
			{
				if (movePending || seenPending)
				{
					read.checked = !item.unseen;
					return;
				}
				seenPending = true;
				read.disabled = true;
				updateSelection();
				error.hidden = true;
				root.setAttribute('aria-busy', 'true');
				try
				{
					const seen = await window.emailSetSeen(root, item, read.checked);
					item.unseen = !seen;
					read.checked = seen;
					row.classList.toggle('is-unseen', !seen);
					moveStatus.textContent = seen ? 'Message marked as read' : 'Message marked as unread';
					await load();
					await loadFolders();
				} catch (exception)
				{
					read.checked = !item.unseen;
					error.textContent = exception.message;
					error.hidden = false;
				} finally
				{
					seenPending = false;
					read.disabled = false;
					updateSelection();
					root.setAttribute('aria-busy', 'false');
					labelReadControl();
				}
			});
			readCell.appendChild(read);
			row.appendChild(readCell);

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
			const response = await fetch(window.emailLink(url), { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to load messages');
			currentSort = String(result.sort ?? currentSort);
			currentOrder = String(result.order ?? currentOrder);
			updateSortIndicators();

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
					window.history.replaceState(null, '', window.emailLink(destination, { start: lastPage > 0 ? lastPage : null }));
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
