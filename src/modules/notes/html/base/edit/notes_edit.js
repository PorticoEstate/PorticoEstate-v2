(function () {
	const app = document.getElementById('notes-edit-app');
	if (!app) return;

	const mode = app.getAttribute('data-mode');
	const apiUrl = app.getAttribute('data-api-url');
	const listUrl = app.getAttribute('data-list-url');
	const csrfName = app.getAttribute('data-csrf-name');
	const csrfValue = app.getAttribute('data-csrf-value');

	const form = document.getElementById('notes-edit-form');
	const errorEl = document.getElementById('notes-edit-error');
	const contentEl = document.getElementById('notes-content');
	const catEl = document.getElementById('notes-cat-id');
	const accessEl = document.getElementById('notes-access');

	if (mode === 'edit' && apiUrl) {
		fetch(apiUrl, {
			headers: { 'Accept': 'application/json' }
		})
			.then(res => res.json())
			.then(data => {
				if (data.error) {
					errorEl.textContent = data.error;
					errorEl.hidden = false;
					return;
				}
				if (contentEl) contentEl.value = data.content || '';
				if (catEl && data.cat_id) catEl.value = data.cat_id;
				if (accessEl) accessEl.checked = (data.access === 'private');
			})
			.catch(err => {
				errorEl.textContent = err.message || 'Error loading note';
				errorEl.hidden = false;
			});
	}

	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			errorEl.hidden = true;

			const payload = {
				content: contentEl.value,
				cat_id: parseInt(catEl.value, 10) || 0,
				access: accessEl.checked ? 'private' : 'public'
			};

			const headers = {
				'Content-Type': 'application/json',
				'Accept': 'application/json'
			};
			if (csrfName && csrfValue) {
				headers[csrfName] = csrfValue;
				headers['X-CSRF-NAME'] = csrfName;
				headers['X-CSRF-VALUE'] = csrfValue;
			}

			const method = (mode === 'edit') ? 'PUT' : 'POST';

			fetch(apiUrl, {
				method: method,
				headers: headers,
				body: JSON.stringify(payload)
			})
				.then(res => res.json())
				.then(data => {
					if (data.error || data.errors) {
						const msg = data.error || (data.errors ? data.errors.join(', ') : 'Save failed');
						errorEl.textContent = msg;
						errorEl.hidden = false;
						return;
					}
					window.location.href = listUrl;
				})
				.catch(err => {
					errorEl.textContent = err.message || 'Error saving note';
					errorEl.hidden = false;
				});
		});
	}
})();
