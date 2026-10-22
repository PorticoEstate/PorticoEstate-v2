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
	const spellcheckButton = document.getElementById('email-compose-spellcheck');
	const spellcheckStatus = document.getElementById('email-compose-spellcheck-status');
	const spellcheckResults = document.getElementById('email-compose-spellcheck-results');
	const addressBook = document.getElementById('email-addressbook-dialog');
	const addressBookForm = document.getElementById('email-addressbook-search');
	const addressBookQuery = document.getElementById('email-addressbook-query');
	const addressBookFilter = document.getElementById('email-addressbook-filter');
	const addressBookResults = document.getElementById('email-addressbook-results');
	const addressBookStatus = document.getElementById('email-addressbook-status');
	let recipientTarget = null;
	const context = JSON.parse(root.dataset.context || '{}');
	const fieldNames = ['to', 'cc', 'bcc', 'subject', 'body'];
	form.inert = true;
	async function loadCompose()
	{
		const response = await fetch(root.dataset.composeApiUrl, { headers: { Accept: 'application/json' } });
		const result = await response.json();
		if (!response.ok) throw new Error(result.error || 'Unable to prepare message');
		fieldNames.forEach(name => { form.elements.namedItem(name).value = result[name] || ''; });
		const signature = document.getElementById('email-compose-signature-option');
		signature.hidden = result.has_signature !== true;
		const signatureInput = form.elements.namedItem('attach_sig');
		if (signatureInput) signatureInput.checked = result.attach_signature === true;
		spellcheckButton.hidden = result.spellcheck_available !== true;
		form.inert = false;
	}

	spellcheckButton.addEventListener('click', async () =>
	{
		spellcheckButton.disabled = true;
		spellcheckStatus.textContent = 'Checking spelling';
		spellcheckResults.replaceChildren();
		try
		{
			const response = await fetch(root.dataset.spellcheckUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders },
				body: JSON.stringify({ body: form.elements.namedItem('body').value })
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to check spelling');
			if (!result.available)
			{
				spellcheckButton.hidden = true;
				spellcheckStatus.textContent = 'Spellcheck is unavailable';
				return;
			}
			const issues = result.issues || [];
			spellcheckStatus.textContent = issues.length ? issues.length + ' possible spelling errors' : 'No spelling errors found';
			issues.forEach(issue =>
			{
				const row = document.createElement('li');
				const word = document.createElement('strong');
				word.textContent = issue.word;
				row.appendChild(word);
				(issue.suggestions || []).forEach(suggestion =>
				{
					const replace = document.createElement('button');
					replace.type = 'button';
					replace.className = 'email-compose-button';
					replace.textContent = suggestion;
					replace.addEventListener('click', () =>
					{
						const escaped = issue.word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
						const matcher = new RegExp('(^|[^\\p{L}\\p{N}_])(' + escaped + ')(?=$|[^\\p{L}\\p{N}_])', 'giu');
						const body = form.elements.namedItem('body');
						body.value = body.value.replace(matcher, (_, prefix) => prefix + suggestion);
						body.dispatchEvent(new Event('input', { bubbles: true }));
						row.remove();
					});
					row.appendChild(replace);
				});
				spellcheckResults.appendChild(row);
			});
		} catch (exception)
		{
			spellcheckStatus.textContent = exception.message;
		} finally
		{
			spellcheckButton.disabled = false;
		}
	});

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

	let contactSearchTimer;
	let contactSearchController;
	let contactSearchVersion = 0;

	function cancelContactSearch()
	{
		clearTimeout(contactSearchTimer);
		contactSearchController?.abort();
		contactSearchVersion++;
	}

	async function searchContacts()
	{
		cancelContactSearch();
		const query = addressBookQuery.value.trim();
		addressBookResults.replaceChildren();
		addressBookStatus.textContent = '';
		if (query.length < 2) return;
		const version = contactSearchVersion;
		contactSearchController = new AbortController();
		addressBookStatus.textContent = 'Searching contacts';
		const url = window.emailLink(root.dataset.contactsApiUrl, {
			q: query, filter: addressBookFilter.value
		});
		try
		{
			const response = await fetch(url, {
				headers: { Accept: 'application/json' }, signal: contactSearchController.signal
			});
			const result = await response.json();
			if (version !== contactSearchVersion) return;
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
					const address = contact.email.trim();
					const alreadySelected = recipientTarget.value.split(/[,;]/).some(recipient =>
					{
						const existingAddress = recipient.match(/<([^<>]+)>/)?.[1] || recipient;
						return existingAddress.trim().toLowerCase() === address.toLowerCase();
					});
					if (alreadySelected) return;
					recipientTarget.value = recipientTarget.value.trim()
						? recipientTarget.value.trim() + ', ' + address
						: address;
					recipientTarget.dispatchEvent(new Event('input', { bubbles: true }));
					recipientTarget.scrollLeft = recipientTarget.scrollWidth;
				});
				item.appendChild(select);
				addressBookResults.appendChild(item);
			});
			addressBookStatus.textContent = contacts.length + ' contacts';
		} catch (exception)
		{
			if (version === contactSearchVersion && exception.name !== 'AbortError') addressBookStatus.textContent = exception.message;
		}
	}

	addressBookQuery.addEventListener('input', () =>
	{
		cancelContactSearch();
		addressBookResults.replaceChildren();
		addressBookStatus.textContent = '';
		if (addressBookQuery.value.trim().length >= 2) contactSearchTimer = setTimeout(searchContacts, 300);
	});
	addressBookFilter.addEventListener('change', searchContacts);
	let addressBookTrigger;
	function closeAddressBook()
	{
		cancelContactSearch();
		addressBook.hidden = true;
		addressBookTrigger?.setAttribute('aria-expanded', 'false');
		addressBookTrigger?.focus({ preventScroll: true });
	}
	addressBook.addEventListener('keydown', event =>
	{
		if (event.key === 'Escape')
		{
			event.preventDefault();
			closeAddressBook();
		}
	});

	root.querySelectorAll('[data-address-target]').forEach(button =>
	{
		button.setAttribute('aria-controls', addressBook.id);
		button.setAttribute('aria-expanded', 'false');
		button.addEventListener('click', () =>
		{
			addressBookTrigger?.setAttribute('aria-expanded', 'false');
			addressBookTrigger = button;
			button.setAttribute('aria-expanded', 'true');
			recipientTarget = form.elements.namedItem(button.dataset.addressTarget);
			const recipientRow = recipientTarget.closest('label');
			recipientRow.after(addressBook);
			addressBook.hidden = false;
			recipientRow.scrollIntoView({ block: 'start', behavior: 'instant' });
			addressBookQuery.focus({ preventScroll: true });
			searchContacts();
		});
	});
	addressBookForm.addEventListener('keydown', event =>
	{
		if (event.key !== 'Enter' || event.target !== addressBookQuery) return;
		event.preventDefault();
		searchContacts();
	});
	document.getElementById('email-addressbook-close').addEventListener('click', closeAddressBook);

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
			if (draft && result.msgnum)
			{
				context.action = 'edit';
				context.msgball = { acctnum: context.msgball?.acctnum ?? context.fldball?.acctnum ?? 0, folder: result.folder, msgnum: result.msgnum };
			}
			if (draft && result.cleanupWarning) status.textContent += ' (previous copy could not be removed)';
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

	Promise.all([loadCompose(), loadAttachments()]).catch(exception =>
	{
		showError(exception.message);
	});
})();
