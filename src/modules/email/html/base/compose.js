(function ()
{
	const root = document.getElementById('email-compose');
	if (!root) return;

	const form = document.getElementById('email-compose-form');
	const error = document.getElementById('email-compose-error');
	const status = document.getElementById('email-compose-status');
	const csrfHeaders = { [root.dataset.csrfName]: root.dataset.csrfValue };
	const context = JSON.parse(root.dataset.context || '{}');

	function showError(message)
	{
		status.hidden = true;
		error.textContent = message;
		error.hidden = false;
	}

	form.addEventListener('input', () =>
	{
		error.hidden = true;
		status.hidden = true;
	});

	form.addEventListener('submit', async event =>
	{
		event.preventDefault();
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

		const sendButton = form.querySelector('[type="submit"]');
		sendButton.disabled = true;
		try
		{
			const response = await fetch(root.dataset.apiUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders },
				body: JSON.stringify(payload)
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to send message');
			status.textContent = 'Message sent';
			status.hidden = false;
			window.location.assign(root.dataset.returnUrl);
		} catch (exception)
		{
			showError(exception.message);
		} finally
		{
			sendButton.disabled = false;
		}
	});
})();
