(function () {
	var root = document.getElementById('todo-view-app');
	if (!root) {
		return;
	}

	var loadingEl = document.getElementById('todo-view-loading');
	var errorEl = document.getElementById('todo-view-error');
	var contentEl = document.getElementById('todo-view-content');
	var editLink = document.getElementById('todo-view-edit');
	var apiUrl = root.dataset.apiUrl;
	var editUrlBase = root.dataset.editUrlBase || '/todo/view/todos';

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
		setText('todo-view-assigned', detail.assigned);

		if (editLink && detail.id) {
			editLink.href = editUrlBase + '/' + encodeURIComponent(detail.id) + '/edit';
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
