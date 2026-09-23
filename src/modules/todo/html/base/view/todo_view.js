(function () {
	var root = document.getElementById('todo-view-app');
	if (!root) {
		return;
	}

	var loadingEl = document.getElementById('todo-view-loading');
	var errorEl = document.getElementById('todo-view-error');
	var contentEl = document.getElementById('todo-view-content');
	var ensurePrefix = (typeof phpgwEnsureServerPrefix === 'function')
		? phpgwEnsureServerPrefix
		: function (url) { return url; };
	var detailsTabEl = document.getElementById('todo-view-tab-details');
	var historyTabEl = document.getElementById('todo-view-tab-history');
	var detailsPanelEl = document.getElementById('todo-view-panel-details');
	var historyPanelEl = document.getElementById('todo-view-panel-history');
	var historyEl = document.getElementById('todo-view-history');
	var editLink = document.getElementById('todo-view-edit');
	var deleteLink = document.getElementById('todo-view-delete');
	var apiUrl = ensurePrefix(root.dataset.apiUrl);
	var editUrlBase = ensurePrefix(root.dataset.editUrlBase || '/todo/view/todos');
	var editUrl = ensurePrefix(root.dataset.editUrl || '');
	var deleteUrl = ensurePrefix(root.dataset.deleteUrl || '');
	var historyLabelUser = root.dataset.langHistoryUser || 'User';
	var historyLabelDate = root.dataset.langHistoryDate || 'Date';
	var historyLabelAction = root.dataset.langHistoryAction || 'Action';
	var historyLabelNewValue = root.dataset.langHistoryNewValue || 'New Value';
	var historyLabelOldValue = root.dataset.langHistoryOldValue || 'Old Value';
	var historyLabelEmpty = root.dataset.langHistoryEmpty || 'No history for this record';

	function setActiveTab(tabName) {
		var showHistory = tabName === 'history';

		if (detailsTabEl) {
			detailsTabEl.classList.toggle('todo-view__tab--active', !showHistory);
			detailsTabEl.setAttribute('aria-selected', showHistory ? 'false' : 'true');
		}

		if (historyTabEl) {
			historyTabEl.classList.toggle('todo-view__tab--active', showHistory);
			historyTabEl.setAttribute('aria-selected', showHistory ? 'true' : 'false');
		}

		if (detailsPanelEl) {
			detailsPanelEl.hidden = showHistory;
		}

		if (historyPanelEl) {
			historyPanelEl.hidden = !showHistory;
		}
	}

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

	function renderHistoryTable(historyRows) {
		if (!historyEl) {
			return;
		}

		var rows = Array.isArray(historyRows) ? historyRows : [];
		if (!rows.length) {
			historyEl.innerHTML = '<p>' + escapeHtml(historyLabelEmpty) + '</p>';
			return;
		}

		var html = ''
			+ '<table>'
			+ '<thead><tr>'
			+ '<th>' + escapeHtml(historyLabelDate) + '</th>'
			+ '<th>' + escapeHtml(historyLabelUser) + '</th>'
			+ '<th>' + escapeHtml(historyLabelAction) + '</th>'
			+ '<th>' + escapeHtml(historyLabelNewValue) + '</th>'
			+ '<th>' + escapeHtml(historyLabelOldValue) + '</th>'
			+ '</tr></thead><tbody>';

		rows.forEach(function (row) {
			html += '<tr>'
				+ '<td>' + escapeHtml(row && row.datetime != null ? row.datetime : '') + '</td>'
				+ '<td>' + escapeHtml(row && row.owner != null ? row.owner : '') + '</td>'
				+ '<td>' + escapeHtml(row && row.status_label != null ? row.status_label : (row && row.status != null ? row.status : '')) + '</td>'
				+ '<td>' + renderMultiline(row && row.new_value != null ? row.new_value : '') + '</td>'
				+ '<td>' + renderMultiline(row && row.old_value != null ? row.old_value : '') + '</td>'
				+ '</tr>';
		});

		html += '</tbody></table>';
		historyEl.innerHTML = html;
	}

	function renderAssigned(detail) {
		var assignedEl = document.getElementById('todo-view-assigned');
		if (!assignedEl) {
			return;
		}

		var entries = Array.isArray(detail && detail.assigned_entries) ? detail.assigned_entries : [];
		if (entries.length) {
			var lines = entries
				.map(function (entry) {
					return entry && entry.name != null ? String(entry.name) : '';
				})
				.filter(function (line) {
					return line !== '';
				});

			if (lines.length) {
				assignedEl.innerHTML = lines.map(function (line) { return escapeHtml(line); }).join('<br>');
				return;
			}
		}

		assignedEl.innerHTML = '-';
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
		renderAssigned(detail);

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

		setActiveTab('details');
	}

	if (detailsTabEl) {
		detailsTabEl.addEventListener('click', function () {
			setActiveTab('details');
		});
	}

	if (historyTabEl) {
		historyTabEl.addEventListener('click', function () {
			setActiveTab('history');
		});
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

			renderHistoryTable(payload.history || []);

			showContent(payload.detail);
		})
		.catch(function (error) {
			showError(error.message);
		});
})();
