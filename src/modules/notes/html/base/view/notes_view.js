(function () {
	const app = document.getElementById('notes-view-app');
	if (!app) return;

	const apiUrl = app.getAttribute('data-api-url');
	const errorEl = document.getElementById('notes-view-error');
	const ownerEl = document.getElementById('notes-view-owner');
	const catEl = document.getElementById('notes-view-category');
	const dateEl = document.getElementById('notes-view-date');
	const accessEl = document.getElementById('notes-view-access');
	const contentEl = document.getElementById('notes-view-content');

	if (apiUrl) {
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
				if (ownerEl) ownerEl.textContent = data.owner_name || '';
				if (catEl) catEl.textContent = data.cat_name || '';
				if (dateEl) dateEl.textContent = data.date_formatted || data.date || '';
				if (accessEl) accessEl.textContent = data.access || 'public';
				if (contentEl) contentEl.textContent = data.content || '';
			})
			.catch(err => {
				errorEl.textContent = err.message || 'Error loading note details';
				errorEl.hidden = false;
			});
	}
})();
