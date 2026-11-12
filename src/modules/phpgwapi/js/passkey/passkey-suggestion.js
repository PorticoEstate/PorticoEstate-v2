(function () {
	if (window.__passkeySuggestionLoaded) {
		return;
	}
	window.__passkeySuggestionLoaded = true;

	const script = document.currentScript;
	const apiUrl = script && script.dataset.apiUrl;
	if (!apiUrl || typeof PublicKeyCredential === 'undefined') {
		return;
	}

	const endpoint = new URL(apiUrl, window.location.href);
	const useDesignsystemet = script.dataset.designSystem === 'digdir';

	// Bootstrap classes and Designsystemet classes conflict in the digdir template, so render only one set.
	const classes = useDesignsystemet ? {
		panel: 'ds-alert',
		title: 'ds-heading',
		description: 'ds-paragraph',
		error: 'ds-validation-message',
		actions: '',
		button: {primary: 'ds-button', secondary: 'ds-button', tertiary: 'ds-button'}
	} : {
		panel: 'alert alert-info',
		title: 'h5',
		description: '',
		error: 'text-danger',
		actions: 'd-flex flex-wrap gap-2',
		button: {primary: 'btn btn-primary', secondary: 'btn btn-secondary', tertiary: 'btn btn-link'}
	};

	function element(tag, text, className) {
		const node = document.createElement(tag);
		node.textContent = text;
		if (className) {
			node.className = className;
		}
		return node;
	}

	function hideSuggestion(panel) {
		panel.remove();
	}

	async function saveChoice(action, panel) {
		const buttons = panel.querySelectorAll('button');
		buttons.forEach(button => { button.disabled = true; });
		try {
			const response = await fetch(endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-CSRF-Token': panel.dataset.csrfToken
				},
				body: JSON.stringify({action: action})
			});
			const result = await response.json();
			if (!response.ok || result.success !== true) {
				throw new Error(result.message || 'Unable to save passkey suggestion preference.');
			}
			hideSuggestion(panel);
		} catch (error) {
			const errorMessage = panel.querySelector('[data-save-error]');
			errorMessage.textContent = error.message || panel.dataset.saveError;
			errorMessage.hidden = false;
			buttons.forEach(button => { button.disabled = false; });
			console.error('Unable to save passkey suggestion preference:', error);
		}
	}

	function renderSuggestion(data) {
		const panel = element('aside', '', classes.panel);
		panel.setAttribute('role', 'region');
		panel.setAttribute('aria-labelledby', 'passkey-suggestion-title');
		panel.dataset.csrfToken = data.csrf_token;
		panel.dataset.saveError = data.save_error;
		panel.style.cssText = 'position:fixed;right:1rem;bottom:1rem;z-index:1050;max-width:32rem;margin:0;box-shadow:0 0.25rem 1rem rgba(0,0,0,.2);';
		if (useDesignsystemet) {
			panel.dataset.color = 'info';
		}

		const title = element('h2', data.title, classes.title);
		title.id = 'passkey-suggestion-title';
		const description = element('p', data.description, classes.description);
		const errorMessage = element('p', '', classes.error);
		if (useDesignsystemet) {
			title.dataset.size = 'xs';
			description.dataset.size = 'sm';
		}
		panel.appendChild(title);
		panel.appendChild(description);
		errorMessage.dataset.saveError = '';
		errorMessage.setAttribute('role', 'alert');
		errorMessage.hidden = true;
		panel.appendChild(errorMessage);

		const actions = element('div', '', classes.actions);
		actions.style.cssText = 'display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;';

		function actionButton(tag, text, variant) {
			const node = element(tag, text, classes.button[variant]);
			if (useDesignsystemet) {
				node.dataset.variant = variant;
				node.dataset.size = 'sm';
			}
			return node;
		}

		// Legacy CSS in the digdir template overrides layered Designsystemet styles on links, so use a button there.
		let setup;
		if (useDesignsystemet) {
			setup = actionButton('button', data.setup, 'primary');
			setup.type = 'button';
			setup.addEventListener('click', function () { window.location.href = data.management_url; });
		} else {
			setup = actionButton('a', data.setup, 'primary');
			setup.href = data.management_url;
		}
		actions.appendChild(setup);

		const later = actionButton('button', data.later, 'secondary');
		later.type = 'button';
		later.addEventListener('click', function () { saveChoice('snooze', panel); });
		actions.appendChild(later);

		const optOut = actionButton('button', data.opt_out, 'tertiary');
		optOut.type = 'button';
		optOut.addEventListener('click', function () { saveChoice('opt_out', panel); });
		actions.appendChild(optOut);

		panel.appendChild(actions);
		document.body.appendChild(panel);
	}

	fetch(endpoint, {
		headers: {'Accept': 'application/json'},
		credentials: 'same-origin',
		cache: 'no-store'
	})
		.then(async response => {
			const result = await response.json();
			if (!response.ok) {
				throw new Error(result.message || 'Unable to check passkey suggestion status.');
			}
			return result;
		})
		.then(result => {
			if (result.success && result.data && result.data.show) {
				renderSuggestion(result.data);
			}
		})
		.catch(error => {
			console.error('Unable to check passkey suggestion status:', error);
		});
})();
