(function ()
{
	const root = document.getElementById('email-message');
	if (!root) return;

	const status = document.getElementById('email-message-status');
	const error = document.getElementById('email-message-error');
	const content = document.getElementById('email-message-content');
	const read = document.getElementById('email-message-seen');
	let currentMessage = null;

	read.addEventListener('change', async () =>
	{
		if (!currentMessage) return;
		const previous = currentMessage.seen;
		read.disabled = true;
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

	function render(item)
	{
		currentMessage = item;
		read.checked = item.seen === true;
		read.disabled = !item.sourceFolder;
		document.getElementById('email-message-subject').textContent = item.subject || '(no subject)';
		document.getElementById('email-message-date').textContent = item.date || '';
		renderAddresses(document.getElementById('email-message-from'), item.from);
		renderAddresses(document.getElementById('email-message-to'), item.to);
		const ccRow = document.getElementById('email-message-cc-row');
		ccRow.hidden = !item.cc || item.cc.length === 0;
		if (!ccRow.hidden) renderAddresses(document.getElementById('email-message-cc'), item.cc);

		const body = document.getElementById('email-message-body');
		body.replaceChildren();
		(item.bodyParts || []).forEach(part =>
		{
			const container = document.createElement('div');
			container.className = 'email-message-body-part';
			container.innerHTML = part.html || '';
			body.appendChild(container);
		});

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
	}

	async function load()
	{
		try
		{
			const response = await fetch(root.dataset.apiUrl, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to load message');
			render(result.item || {});
		} catch (exception)
		{
			status.hidden = true;
			error.textContent = exception.message;
			error.hidden = false;
		}
	}

	load();
})();
