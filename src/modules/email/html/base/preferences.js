(function ()
{
	const root = document.getElementById('email-preferences');
	if (!root) return;

	const form = document.getElementById('email-preferences-form');
	const status = document.getElementById('email-preferences-status');
	const error = document.getElementById('email-preferences-error');
	const standardFields = document.getElementById('email-preferences-standard');
	const customFields = document.getElementById('email-preferences-custom-fields');
	const customEnabled = document.getElementById('email-preferences-custom-enabled');
	let schema = [];

	function renderField(field, parent)
	{
		const row = document.createElement('div');
		row.className = 'email-preference';
		const label = document.createElement('label');
		label.className = 'email-preference-label';
		label.textContent = field.label;
		if (field.description)
		{
			const description = document.createElement('span');
			description.className = 'email-preference-description';
			description.textContent = field.description;
			label.appendChild(description);
		}
		const control = document.createElement('div');
		control.className = 'email-preference-control';
		let input;
		if (field.widget === 'checkbox')
		{
			input = document.createElement('input');
			input.type = 'checkbox';
			input.checked = field.checked;
			label.replaceChildren(input, document.createTextNode(field.label));
			row.appendChild(label);
			row.appendChild(control);
			parent.appendChild(row);
			input.name = field.id;
			return;
		}
		if (field.widget === 'combobox')
		{
			input = document.createElement('select');
			Object.entries(field.values || {}).forEach(([value, text]) =>
			{
				const option = document.createElement('option');
				option.value = value;
				option.textContent = text;
				option.selected = value === field.value;
				input.appendChild(option);
			});
		} else if (field.widget === 'textarea')
		{
			input = document.createElement('textarea');
			input.value = field.value;
		} else
		{
			input = document.createElement('input');
			input.type = field.widget === 'passwordbox' ? 'password' : 'text';
			input.value = field.hidden ? '' : field.value;
			if (field.hidden) input.autocomplete = 'new-password';
		}
		input.name = field.id;
		control.appendChild(input);
		row.appendChild(label);
		row.appendChild(control);
		if (field.id === 'imap_auth_type' && field.google_help)
		{
			const help = document.createElement('p');
			help.className = 'email-preference-description email-preference-google-help';
			help.setAttribute('role', 'note');
			help.append(document.createTextNode(field.google_help + ' '));
			const consoleLink = document.createElement('a');
			consoleLink.href = 'https://console.cloud.google.com/auth/clients';
			consoleLink.target = '_blank';
			consoleLink.rel = 'noopener noreferrer';
			consoleLink.textContent = 'Google Cloud Console';
			help.append(consoleLink);
			help.hidden = input.value !== 'google_oauth';
			input.addEventListener('change', () => { help.hidden = input.value !== 'google_oauth'; });
			row.appendChild(help);
		}
		parent.appendChild(row);
	}

	async function load()
	{
		try
		{
			const response = await fetch(root.dataset.apiUrl, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to load preferences');
			schema = result.fields || [];
			if (root.dataset.accountMode) root.dataset.accountId = String(result.id);
			standardFields.replaceChildren();
			customFields.replaceChildren();
			const customToggle = schema.find(field => field.id === 'use_custom_settings');
			customEnabled.checked = Boolean(customToggle && customToggle.checked);
			schema.forEach(field =>
			{
				if (field.id === 'use_custom_settings') return;
				renderField(field, field.group === 'custom' ? customFields : standardFields);
			});
			customFields.hidden = !customEnabled.checked;
			form.hidden = false;
			status.textContent = '';
		} catch (exception)
		{
			status.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
		}
	}

	customEnabled.addEventListener('change', () =>
	{
		customFields.hidden = !customEnabled.checked;
	});

	form.addEventListener('submit', async event =>
	{
		event.preventDefault();
		error.hidden = true;
		const values = {};
		schema.forEach(field =>
		{
			if (field.id === 'use_custom_settings') return;
			if (field.group === 'custom' && !customEnabled.checked) return;
			const input = form.elements.namedItem(field.id);
			if (!input) return;
			if (field.widget === 'checkbox')
			{
				if (input.checked) values[field.id] = true;
			} else
			{
				values[field.id] = input.value;
			}
		});
		if (customEnabled.checked) values.use_custom_settings = true;
		const button = form.querySelector('[type="submit"]');
		button.disabled = true;
		try
		{
			const payload = { values };
			if (root.dataset.accountMode) payload.id = Number(root.dataset.accountId);
			const response = await fetch(root.dataset.saveUrl, {
				method: 'POST',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-CSRF-NAME': root.dataset.csrfName,
					'X-CSRF-VALUE': root.dataset.csrfValue
				},
				body: JSON.stringify(payload)
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to save preferences');
			if (root.dataset.accountMode)
			{
				window.location.assign(root.dataset.accountsUrl);
				return;
			}
			status.textContent = 'Preferences saved';
		} catch (exception)
		{
			error.textContent = exception.message;
			error.hidden = false;
		} finally
		{
			button.disabled = false;
		}
	});

	load();
})();
