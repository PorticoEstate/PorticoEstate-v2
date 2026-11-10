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
		const panel = element('aside', '', 'alert alert-info');
		panel.setAttribute('role', 'region');
		panel.setAttribute('aria-labelledby', 'passkey-suggestion-title');
		panel.dataset.csrfToken = data.csrf_token;
		panel.dataset.saveError = data.save_error;
		panel.style.cssText = 'position:fixed;right:1rem;bottom:1rem;z-index:1050;max-width:32rem;margin:0;box-shadow:0 0.25rem 1rem rgba(0,0,0,.2);';

		const title = element('h2', data.title);
		title.id = 'passkey-suggestion-title';
		title.className = 'h5';
		panel.appendChild(title);
		panel.appendChild(element('p', data.description));
		const errorMessage = element('p', '', 'text-danger');
		errorMessage.dataset.saveError = '';
		errorMessage.setAttribute('role', 'alert');
		errorMessage.hidden = true;
		panel.appendChild(errorMessage);

		const actions = element('div', '', 'd-flex flex-wrap gap-2');
		const setup = element('a', data.setup, 'btn btn-primary');
		setup.href = data.management_url;
		actions.appendChild(setup);

		const later = element('button', data.later, 'btn btn-secondary');
		later.type = 'button';
		later.addEventListener('click', function () { saveChoice('snooze', panel); });
		actions.appendChild(later);

		const optOut = element('button', data.opt_out, 'btn btn-link');
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
