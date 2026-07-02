(function () {
	var root = document.getElementById('todo-view-app');
	if (!root) {
		return;
	}

	var loadingEl = document.getElementById('todo-view-loading');
	var errorEl = document.getElementById('todo-view-error');
	var contentEl = document.getElementById('todo-view-content');
	var editLink = document.getElementById('todo-view-edit');
	var deleteLink = document.getElementById('todo-view-delete');
	var apiUrl = root.dataset.apiUrl;
	var editUrlBase = root.dataset.editUrlBase || '/todo/view/todos';
	var editUrl = root.dataset.editUrl || '';
	var deleteUrl = root.dataset.deleteUrl || '';

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/\"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function renderMultiline(value) {
		var normalized = String(value == null ? '' : value)
			.replace(/<br\s*\/?>/gi, '\n')
			.replace(/\r\n/g, '\n')
			.replace(/\r/g, '\n');

		return escapeHtml(normalized).replace(/\n/g, '<br>');
	}

	function setText(id, value) {
		var el = document.getElementById(id);
		if (!el) {
			return;
		}
		el.textContent = value == null || value === '' ? '-' : String(value);
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

		setText('todo-view-title', detail.title);
		setText('todo-view-category', detail.category);
		setText('todo-view-descr', detail.descr);
		setText('todo-view-parent', detail.parent);
		setText('todo-view-status', detail.status + '%');
		setText('todo-view-pri', detail.pri);
		setText('todo-view-sdate', detail.sdate);
		setText('todo-view-edate', detail.edate);
		setText('todo-view-access', detail.access);
		setText('todo-view-owner', detail.owner);
		var assignedEl = document.getElementById('todo-view-assigned');
		if (assignedEl) {
			assignedEl.innerHTML = renderMultiline(detail.assigned);
		}

		if (editLink) {
			if (editUrl) {
				editLink.href = editUrl;
			} else if (detail.id) {
				editLink.href = editUrlBase + '/' + encodeURIComponent(detail.id) + '/edit';
			}
		}

		if (deleteLink) {
			if (deleteUrl) {
				deleteLink.href = deleteUrl;
			} else if (detail.id) {
				deleteLink.href = editUrlBase + '/' + encodeURIComponent(detail.id) + '/delete';
			}
		}
	}

	fetch(apiUrl, { credentials: 'same-origin' })
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
})();
