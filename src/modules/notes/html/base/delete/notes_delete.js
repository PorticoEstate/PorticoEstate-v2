(function () {
	const app = document.getElementById('notes-delete-app');
	if (!app) return;

	const apiUrl = app.getAttribute('data-api-url');
	const listUrl = app.getAttribute('data-list-url');
	const csrfName = app.getAttribute('data-csrf-name');
	const csrfValue = app.getAttribute('data-csrf-value');

	const confirmBtn = document.getElementById('notes-delete-confirm');
	const errorEl = document.getElementById('notes-delete-error');

	if (confirmBtn) {
		confirmBtn.addEventListener('click', function () {
			errorEl.hidden = true;
			confirmBtn.disabled = true;

			const headers = {
				'Accept': 'application/json'
			};
			if (csrfName && csrfValue) {
				headers[csrfName] = csrfValue;
				headers['X-CSRF-NAME'] = csrfName;
				headers['X-CSRF-VALUE'] = csrfValue;
			}

			fetch(apiUrl, {
				method: 'DELETE',
				headers: headers
			})
				.then(res => res.json())
				.then(data => {
					if (data.error) {
						errorEl.textContent = data.error;
						errorEl.hidden = false;
						confirmBtn.disabled = false;
						return;
					}
					window.location.href = listUrl;
				})
				.catch(err => {
					errorEl.textContent = err.message || 'Error deleting note';
					errorEl.hidden = false;
					confirmBtn.disabled = false;
				});
		});
	}
})();
