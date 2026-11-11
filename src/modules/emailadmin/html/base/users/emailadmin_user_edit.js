(function ()
{
	const root = document.getElementById('emailadmin-user-editor');
	if (!root) return;

	const form = document.getElementById('emailadmin-user-form');
	const errorBox = document.getElementById('emailadmin-user-error');
	const statusBox = document.getElementById('emailadmin-user-status');
	const csrfHeaders = {
		'X-CSRF-NAME': root.dataset.csrfName,
		'X-CSRF-VALUE': root.dataset.csrfValue
	};

	function showError(message)
	{
		statusBox.hidden = true;
		errorBox.textContent = message;
		errorBox.hidden = false;
	}

	function addresses(value)
	{
		return (Array.isArray(value) ? value : []).join('\n');
	}

	async function load()
	{
		const response = await fetch(root.dataset.apiUrl, { headers: { Accept: 'application/json' } });
		const result = await response.json();
		if (!response.ok) throw new Error(result.error || 'Unable to load user email settings');

		const item = result.item || {};
		form.elements.namedItem('mailLocalAddress').value = item.mailLocalAddress || '';
		form.elements.namedItem('mailAlternateAddress').value = addresses(item.mailAlternateAddress);
		form.elements.namedItem('mailForwardingAddress').value = addresses(item.mailForwardingAddress);
		form.elements.namedItem('quotaLimit').value = item.quotaLimit || '';
		form.elements.namedItem('accountActive').checked = Boolean(item.accountActive);
		form.elements.namedItem('forwardOnly').checked = Boolean(item.forwardOnly);
	}

	form.addEventListener('input', () =>
	{
		errorBox.hidden = true;
		statusBox.hidden = true;
	});

	form.addEventListener('submit', async event =>
	{
		event.preventDefault();
		errorBox.hidden = true;
		statusBox.hidden = true;
		const data = new FormData(form);
		const body = {
			mailLocalAddress: data.get('mailLocalAddress'),
			mailAlternateAddress: String(data.get('mailAlternateAddress') || '').split(/\r?\n/).map(value => value.trim()).filter(Boolean),
			mailForwardingAddress: String(data.get('mailForwardingAddress') || '').split(/\r?\n/).map(value => value.trim()).filter(Boolean),
			quotaLimit: data.get('quotaLimit'),
			accountActive: form.elements.namedItem('accountActive').checked,
			forwardOnly: form.elements.namedItem('forwardOnly').checked
		};

		const saveButton = form.querySelector('[type="submit"]');
		saveButton.disabled = true;
		try
		{
			const response = await fetch(root.dataset.apiUrl, {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders },
				body: JSON.stringify(body)
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to save user email settings');
			statusBox.textContent = 'Settings saved';
			statusBox.hidden = false;
		} catch (error)
		{
			showError(error.message);
		} finally
		{
			saveButton.disabled = false;
		}
	});

	load().catch(error => showError(error.message));
})();
