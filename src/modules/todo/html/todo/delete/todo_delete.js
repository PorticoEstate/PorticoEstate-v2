(function () {
	var root = document.getElementById('todo-delete-app');
	if (!root) {
		return;
	}

	var loadingEl = document.getElementById('todo-delete-loading');
	var errorEl = document.getElementById('todo-delete-error');
	var contentEl = document.getElementById('todo-delete-content');
	var titleEl = document.getElementById('todo-delete-title');
	var subsWrapEl = document.getElementById('todo-delete-subs-wrap');
	var subsEl = document.getElementById('todo-delete-subs');
	var confirmBtn = document.getElementById('todo-delete-confirm');
	var detailUrl = root.dataset.detailUrl;
	var apiUrl = root.dataset.apiUrl;
	var backUrl = root.dataset.backUrl;

	function showError(message) {
		loadingEl.hidden = true;
		contentEl.hidden = true;
		errorEl.hidden = false;
		errorEl.textContent = message || root.dataset.langError || 'Error';
	}

	function showContent(detail) {
		loadingEl.hidden = true;
		errorEl.hidden = true;
		contentEl.hidden = false;

		titleEl.textContent = detail.title || '-';
		if (detail.has_subs) {
			subsWrapEl.hidden = false;
		}
	}

	function loadDetail() {
		fetch(detailUrl, { credentials: 'same-origin' })
			.then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.detail) {
					throw new Error(root.dataset.langError || 'Error');
				}
				showContent(payload.detail);
			})
			.catch(function (error) {
				showError(error.message);
			});
	}

	confirmBtn.addEventListener('click', function () {
		confirmBtn.disabled = true;

		var url = apiUrl;
		if (subsEl && subsEl.checked) {
			url += '?subs=1';
		}

		fetch(url, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json'
			}
		})
			.then(function (response) {
				return response.json().catch(function () { return {}; }).then(function (data) {
					if (!response.ok) {
						throw new Error(data.error || ('HTTP ' + response.status));
					}
					return data;
				});
			})
			.then(function () {
				window.location.href = backUrl;
			})
			.catch(function (error) {
				confirmBtn.disabled = false;
				showError(error.message);
			});
	});

	loadDetail();
})();
