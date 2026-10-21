(function ()
{
	const root = document.getElementById('email-compose');
	if (!root) return;

	const form = document.getElementById('email-compose-form');
	const error = document.getElementById('email-compose-error');
	const status = document.getElementById('email-compose-status');
	const csrfHeaders = {
		'X-CSRF-NAME': root.dataset.csrfName,
		'X-CSRF-VALUE': root.dataset.csrfValue
	};
	const attachmentList = document.getElementById('email-compose-attachment-list');
	const attachmentStatus = document.getElementById('email-compose-attachments-status');
	const attachmentFile = document.getElementById('email-compose-file');
	const uploadButton = document.getElementById('email-compose-upload');
	const addressBook = document.getElementById('email-addressbook-dialog');
	const addressBookForm = document.getElementById('email-addressbook-search');
	const addressBookQuery = document.getElementById('email-addressbook-query');
	const addressBookFilter = document.getElementById('email-addressbook-filter');
	const addressBookResults = document.getElementById('email-addressbook-results');
	const addressBookStatus = document.getElementById('email-addressbook-status');
	let recipientTarget = null;
	const context = JSON.parse(root.dataset.context || '{}');

	function showError(message)
	{
		status.hidden = true;
		error.textContent = message;
		error.hidden = false;
	}

	async function loadAttachments()
	{
		const response = await fetch(root.dataset.attachmentsApiUrl, { headers: { Accept: 'application/json' } });
		const result = await response.json();
		if (!response.ok) throw new Error(result.reason || result.error || 'Unable to load attachments');
		attachmentList.replaceChildren();
		(result.items || []).forEach(attachment =>
		{
			const item = document.createElement('li');
			const name = document.createElement('span');
			name.textContent = attachment.name + ' (' + Math.ceil(attachment.size / 1024) + ' KiB)';
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.textContent = 'Remove';
			remove.addEventListener('click', async () =>
			{
				remove.disabled = true;
				try
				{
					const deleteResponse = await fetch(root.dataset.attachmentDeleteUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders },
						body: JSON.stringify({ ids: [attachment.id] })
					});
					const deleteResult = await deleteResponse.json();
					if (!deleteResponse.ok) throw new Error(deleteResult.reason || deleteResult.error || 'Unable to remove attachment');
					await loadAttachments();
				} catch (exception)
				{
					attachmentStatus.textContent = exception.message;
					remove.disabled = false;
				}
			});
			item.append(name, remove);
			attachmentList.appendChild(item);
		});
	}

	async function searchContacts()
	{
		addressBookStatus.textContent = 'Searching contacts';
		addressBookResults.replaceChildren();
		const url = new URL(root.dataset.contactsApiUrl, window.location.href);
		url.searchParams.set('q', addressBookQuery.value.trim());
		url.searchParams.set('filter', addressBookFilter.value);
		try
		{
			const response = await fetch(url, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to search contacts');
			const contacts = result.items || [];
			contacts.forEach(contact =>
			{
				const item = document.createElement('li');
				const select = document.createElement('button');
				select.type = 'button';
				select.textContent = contact.name + ' <' + contact.email + '>';
				select.addEventListener('click', () =>
				{
					if (!recipientTarget) return;
					const address = contact.email;
					recipientTarget.value = recipientTarget.value.trim()
						? recipientTarget.value.trim() + ', ' + address
						: address;
					recipientTarget.dispatchEvent(new Event('input', { bubbles: true }));
				});
				item.appendChild(select);
				addressBookResults.appendChild(item);
			});
			addressBookStatus.textContent = contacts.length + ' contacts';
		} catch (exception)
		{
			addressBookStatus.textContent = exception.message;
		}
	}

	root.querySelectorAll('[data-address-target]').forEach(button =>
	{
		button.addEventListener('click', () =>
		{
			recipientTarget = form.elements.namedItem(button.dataset.addressTarget);
			if (typeof addressBook.showModal === 'function') addressBook.showModal();
			else addressBook.setAttribute('open', '');
			searchContacts();
		});
	});
	addressBookForm.addEventListener('submit', event =>
	{
		event.preventDefault();
		searchContacts();
	});
	document.getElementById('email-addressbook-close').addEventListener('click', () => addressBook.close());

	uploadButton.addEventListener('click', async () =>
	{
		const file = attachmentFile.files[0];
		if (!file) return;
		if (file.size > 25 * 1024 * 1024)
		{
			attachmentStatus.textContent = 'Attachment exceeds the 25 MiB limit';
			return;
		}
		uploadButton.disabled = true;
		attachmentStatus.textContent = 'Uploading attachment';
		const upload = new FormData();
		upload.append('uploadedfile', file);
		try
		{
			const response = await fetch(root.dataset.attachmentUploadUrl, {
				method: 'POST',
				headers: { Accept: 'application/json', ...csrfHeaders },
				body: upload
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to upload attachment');
			attachmentFile.value = '';
			attachmentStatus.textContent = '';
			await loadAttachments();
		} catch (exception)
		{
			attachmentStatus.textContent = exception.message;
		} finally
		{
			uploadButton.disabled = false;
		}
	});

	form.addEventListener('input', () =>
	{
		error.hidden = true;
		status.hidden = true;
	});

	form.addEventListener('submit', async event =>
	{
		event.preventDefault();
		const draft = event.submitter?.value === 'draft';
		error.hidden = true;
		status.hidden = true;
		const data = new FormData(form);
		const payload = {
			to: data.get('to') || '',
			cc: data.get('cc') || '',
			bcc: data.get('bcc') || '',
			subject: data.get('subject') || '',
			body: data.get('body') || '',
			action: context.action || 'new',
			attach_sig: Boolean(form.elements.namedItem('attach_sig')?.checked),
			req_notify: Boolean(form.elements.namedItem('req_notify')?.checked)
		};
		['msgball', 'fldball', 'fwd_proc', 'sort', 'order', 'start'].forEach(key =>
		{
			if (context[key] !== undefined) payload[key] = context[key];
		});

		const submitButtons = form.querySelectorAll('[type="submit"]');
		submitButtons.forEach(button => { button.disabled = true; });
		try
		{
			const response = await fetch(draft ? root.dataset.draftUrl : root.dataset.apiUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders },
				body: JSON.stringify(payload)
			});
			const result = await response.json();
			if (!response.ok || (draft && !result.saved)) throw new Error(result.error || (draft ? 'Unable to save draft' : 'Unable to send message'));
			status.textContent = draft ? 'Draft saved' : 'Message sent';
			status.hidden = false;
			if (!draft) window.location.assign(root.dataset.returnUrl);
		} catch (exception)
		{
			showError(exception.message);
		} finally
		{
			submitButtons.forEach(button => { button.disabled = false; });
		}
	});

	loadAttachments().catch(exception =>
	{
		attachmentStatus.textContent = exception.message;
	});
})();
