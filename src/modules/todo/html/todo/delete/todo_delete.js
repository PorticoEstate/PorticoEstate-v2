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
	var csrfNameKey = root.dataset.csrfNameKey || '';
	var csrfValueKey = root.dataset.csrfValueKey || '';
	var csrfName = root.dataset.csrfName || '';
	var csrfValue = root.dataset.csrfValue || '';

	function getDeleteReasonMessage(reason, fallback) {
		switch (reason) {
			case 'insufficient_grants_owner':
				return root.dataset.langDeleteDeniedOwner || fallback;
			case 'insufficient_grants_parent':
				return root.dataset.langDeleteDeniedParent || fallback;
			case 'parent_not_found':
				return root.dataset.langDeleteParentNotFound || fallback;
			case 'not_found':
				return root.dataset.langDeleteNotFound || fallback;
			default:
				return fallback;
		}
	}

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

		var headers = {
			'Accept': 'application/json'
		};
		if (csrfNameKey && csrfValueKey && csrfName && csrfValue) {
			headers[csrfNameKey] = csrfName;
			headers[csrfValueKey] = csrfValue;
			headers['X-CSRF-NAME'] = csrfName;
			headers['X-CSRF-VALUE'] = csrfValue;
		}

		fetch(url, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: headers
		})
			.then(function (response) {
				return response.json().catch(function () { return {}; }).then(function (data) {
					if (!response.ok) {
						var fallbackMessage = data.error || ('HTTP ' + response.status);
						throw new Error(getDeleteReasonMessage(data.reason, fallbackMessage));
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
