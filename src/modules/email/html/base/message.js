(function ()
{
	const root = document.getElementById('email-message');
	if (!root) return;

	const status = document.getElementById('email-message-status');
	const error = document.getElementById('email-message-error');
	const content = document.getElementById('email-message-content');
	const read = document.getElementById('email-message-seen');
	let currentMessage = null;
	const deleteButton = document.getElementById('email-message-delete');
	const moveButton = document.getElementById('email-message-move');
	const targetFolder = document.getElementById('email-message-target');
	const printButton = document.getElementById('email-message-print');
	const formatButton = document.getElementById('email-message-format');
	const htmlButton = document.getElementById('email-message-html');
	let actionPending = false;
	let formatted = true;
	let allowSanitizedHtml = false;
	let renderedParts = [];

	printButton.addEventListener('click', () => window.print());
	formatButton.addEventListener('click', () =>
	{
		formatted = !formatted;
		formatButton.textContent = formatted ? 'View unformatted' : 'View formatted';
		renderBody();
	});
	htmlButton.addEventListener('click', () =>
	{
		allowSanitizedHtml = !allowSanitizedHtml;
		htmlButton.textContent = allowSanitizedHtml ? 'Hide HTML' : 'View sanitized HTML';
		renderBody();
	});
	deleteButton.addEventListener('click', () => runAction('delete'));
	moveButton.addEventListener('click', () => runAction('move'));
	async function runAction(action)
	{
		if (!currentMessage || read.disabled || actionPending || (action === 'move' && !targetFolder.value)) return;
		if (action === 'delete' && !window.confirm('Delete this message? Messages in Trash, or accounts without Trash, are permanently deleted.')) return;
		actionPending = true;
		read.disabled = deleteButton.disabled = moveButton.disabled = targetFolder.disabled = true;
		error.hidden = true;
		try
		{
			const payload = { action, account: currentMessage.account, confirmed: action === 'delete', messages: [{ acctnum: currentMessage.account, folder: currentMessage.sourceFolder, msgnum: currentMessage.messageNumber }] };
			if (action === 'move') payload.target_fldball = { acctnum: currentMessage.account, folder: targetFolder.value };
			const result = await window.emailMessageAction(root, payload);
			if (result.failed.length || !result.completed.length) throw new Error(result.failed[0]?.error || 'Unable to update message');
			window.location.assign(root.dataset.backUrl);
		} catch (exception)
		{
			error.textContent = exception.message;
			error.hidden = false;
		} finally
		{
			actionPending = false;
			read.disabled = deleteButton.disabled = false;
			targetFolder.disabled = !targetFolder.options.length;
			moveButton.disabled = !targetFolder.value;
		}
	}

	read.addEventListener('change', async () =>
	{
		if (!currentMessage) return;
		const previous = currentMessage.seen;
		read.disabled = true;
		deleteButton.disabled = moveButton.disabled = true;
		error.hidden = true;
		try
		{
			currentMessage.seen = await window.emailSetSeen(root, currentMessage, read.checked);
			read.checked = currentMessage.seen;
			status.textContent = currentMessage.seen ? 'Message marked as read' : 'Message marked as unread';
			status.hidden = false;
		} catch (exception)
		{
			read.checked = previous;
			error.textContent = exception.message;
			error.hidden = false;
		} finally
		{
			read.disabled = false;
			deleteButton.disabled = false;
			moveButton.disabled = !targetFolder.value;
		}
	});

	function renderAddresses(target, addresses)
	{
		target.replaceChildren();
		(addresses || []).forEach((address, index) =>
		{
			if (index) target.appendChild(document.createTextNode(', '));
			const name = address.name || address.address || '';
			target.appendChild(document.createTextNode(name));
			if (address.address && address.name && address.address !== address.name)
			{
				target.appendChild(document.createTextNode(' <' + address.address + '>'));
			}
		});
	}

	function renderBody()
	{
		const body = document.getElementById('email-message-body');
		body.replaceChildren();
		renderedParts.forEach(part =>
		{
			const container = document.createElement('div');
			container.className = 'email-message-body-part';
			if (part.requiresConfirmation && !allowSanitizedHtml)
			{
				const source = document.createElement('pre');
				source.className = 'email-message-source';
				source.textContent = part.source || '';
				container.appendChild(source);
			} else if (!formatted && typeof part.text === 'string')
			{
				const plain = document.createElement('pre');
				plain.className = 'email-message-source';
				plain.textContent = part.text;
				container.appendChild(plain);
			} else
			{
				container.innerHTML = part.html || '';
			}
			body.appendChild(container);
		});
	}

	function render(item)
	{
		currentMessage = item;
		read.checked = item.seen === true;
		read.disabled = !item.sourceFolder;
		deleteButton.disabled = !item.sourceFolder;
		printButton.disabled = false;
		renderedParts = item.bodyParts || [];
		formatted = true;
		allowSanitizedHtml = false;
		formatButton.hidden = !renderedParts.some(part => typeof part.text === 'string');
		formatButton.textContent = 'View unformatted';
		htmlButton.hidden = !renderedParts.some(part => part.requiresConfirmation === true);
		htmlButton.textContent = 'View sanitized HTML';
		document.getElementById('email-message-edit').hidden = item.isDraft !== true;
		['previous', 'next'].forEach(direction =>
		{
			const link = document.getElementById('email-message-' + direction);
			link.hidden = !item[direction + 'Url'];
			if (!link.hidden) link.href = item[direction + 'Url'];
		});
		document.getElementById('email-message-subject').textContent = item.subject || '(no subject)';
		document.getElementById('email-message-date').textContent = item.date || '';
		renderAddresses(document.getElementById('email-message-from'), item.from);
		renderAddresses(document.getElementById('email-message-to'), item.to);
		const ccRow = document.getElementById('email-message-cc-row');
		ccRow.hidden = !item.cc || item.cc.length === 0;
		if (!ccRow.hidden) renderAddresses(document.getElementById('email-message-cc'), item.cc);

		renderBody();

		const attachments = item.attachments || [];
		const attachmentSection = document.getElementById('email-message-attachments');
		attachmentSection.hidden = attachments.length === 0;
		const attachmentList = document.getElementById('email-message-attachment-list');
		attachmentList.replaceChildren();
		attachments.forEach(attachment =>
		{
			const row = document.createElement('li');
			const size = Number(attachment.size) || 0;
			if (attachment.downloadUrl)
			{
				const link = document.createElement('a');
				link.href = attachment.downloadUrl;
				link.textContent = attachment.fileName || 'Attachment';
				row.appendChild(link);
			} else
			{
				row.textContent = attachment.fileName || 'Attachment';
			}
			row.appendChild(document.createTextNode(' (' + size.toLocaleString() + ' bytes)'));
			attachmentList.appendChild(row);
		});

		status.hidden = true;
		content.hidden = false;
		if (new URL(window.location.href).searchParams.get('print') === '1') window.requestAnimationFrame(() => window.print());
	}

	async function load()
	{
		try
		{
			const response = await fetch(root.dataset.apiUrl, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to load message');
			render(result.item || {});
			try
			{
				const foldersResponse = await fetch(root.dataset.foldersUrl, { headers: { Accept: 'application/json' } });
				const folders = await foldersResponse.json();
				if (!foldersResponse.ok) throw new Error(folders.error || 'Unable to load destination folders');
				window.emailFolderHierarchy(folders.items || [], folders.delimiter || '').forEach(folder =>
				{
					if (folder.longName !== currentMessage.sourceFolder) targetFolder.add(new Option('\u00a0\u00a0'.repeat(folder.depth) + folder.label, folder.longName));
				});
				targetFolder.disabled = !targetFolder.options.length;
				moveButton.disabled = !targetFolder.value || read.disabled;
			} catch (exception)
			{
				error.textContent = exception.message;
				error.hidden = false;
			}
		} catch (exception)
		{
			status.hidden = true;
			error.textContent = exception.message;
			error.hidden = false;
		}
	}

	load();
})();
