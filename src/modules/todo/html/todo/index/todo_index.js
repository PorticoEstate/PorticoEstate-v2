(function () {
	var root = document.getElementById('todo-app');
	if (!root) {
		return;
	}

	var apiUrl = root.dataset.apiUrl;
	var viewUrl = root.dataset.viewUrl;
	var csvUrl = apiUrl + '/export/csv';
	var loadingEl = document.getElementById('todo-loading');
	var errorEl = document.getElementById('todo-error');
	var tableEl = document.getElementById('todo-table');
	var tbodyEl = document.getElementById('todo-table-body');
	var searchEl = document.getElementById('todo-search');
	var filterEl = document.getElementById('todo-filter');
	var catEl = document.getElementById('todo-cat');
	var csvBtn = document.getElementById('todo-csv');
	var initialParams = new URLSearchParams(window.location.search);
	var initialCatId = initialParams.get('cat_id') || '0';

	if (initialParams.get('filter')) {
		filterEl.value = initialParams.get('filter');
	}

	if (initialParams.get('search')) {
		searchEl.value = initialParams.get('search');
	}

	if (catEl && initialCatId) {
		catEl.value = initialCatId;
	}

	function makeActionLink(url, label) {
		if (!url) {
			return '&nbsp;';
		}
		return '<a href="' + escapeHtml(url) + '">' + escapeHtml(label) + '</a>';
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/\"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function setState(state, errorMessage) {
		loadingEl.hidden = state !== 'loading';
		errorEl.hidden = state !== 'error';
		tableEl.hidden = state !== 'ready';
		if (state === 'error') {
			errorEl.textContent = errorMessage || root.dataset.langError;
		}
	}

	function renderRows(items) {
		tbodyEl.innerHTML = '';
		if (!items || !items.length) {
			var emptyRow = document.createElement('tr');
			emptyRow.innerHTML = '<td colspan="12">' + escapeHtml(root.dataset.langEmpty) + '</td>';
			tbodyEl.appendChild(emptyRow);
			return;
		}

		items.forEach(function (item) {
			var row = document.createElement('tr');
			var actions = item.actions || {};
			var href = actions.view || (viewUrl + '&todo_id=' + encodeURIComponent(item.id));
			row.innerHTML = '' +
				'<td>' + escapeHtml(item.id || '') + '</td>' +
				'<td><a href="' + href + '">' + escapeHtml(item.title || '') + '</a></td>' +
				'<td>' + escapeHtml(item.status || '') + '</td>' +
				'<td>' + escapeHtml(item.pri || '') + '</td>' +
				'<td>' + escapeHtml(item.sdate || '') + '</td>' +
				'<td>' + escapeHtml(item.edate || '') + '</td>' +
				'<td>' + escapeHtml(item.owner || '') + '</td>' +
				'<td>' + escapeHtml(item.assigned || '') + '</td>' +
				'<td>' + makeActionLink(actions.view, root.dataset.langView || 'View') + '</td>' +
				'<td>' + makeActionLink(actions.edit, root.dataset.langEdit || 'Edit') + '</td>' +
				'<td>' + makeActionLink(actions.delete, root.dataset.langDelete || 'Delete') + '</td>' +
				'<td>' + makeActionLink(actions.subadd, root.dataset.langAddSub || 'Add Sub') + '</td>';
			tbodyEl.appendChild(row);
		});
	}

	function buildQueryParams() {
		var params = new URLSearchParams();
		params.set('start', '0');
		params.set('limit', '100');
		params.set('sort', 'created');
		params.set('dir', 'DESC');
		params.set('filter', filterEl.value || 'none');
		params.set('search', searchEl.value || '');
		if (catEl && catEl.value) {
			params.set('cat_id', catEl.value);
		}
		return params;
	}

	function loadTodos() {
		setState('loading');

		var params = buildQueryParams();

		fetch(apiUrl + '?' + params.toString(), {
			credentials: 'same-origin'
		})
			.then(function (res) {
				if (!res.ok) {
					throw new Error('HTTP ' + res.status);
				}
				return res.json();
			})
			.then(function (payload) {
				renderRows(payload.items || []);
				setState('ready');
			})
			.catch(function (err) {
				setState('error', err.message);
			});
	}

	var searchTimer = null;
	searchEl.addEventListener('input', function () {
		if (searchTimer) {
			clearTimeout(searchTimer);
		}
		searchTimer = setTimeout(loadTodos, 250);
	});

	filterEl.addEventListener('change', loadTodos);

	if (catEl) {
		catEl.addEventListener('change', loadTodos);
	}

	if (csvBtn) {
		csvBtn.addEventListener('click', function () {
			window.location.href = csvUrl + '?' + buildQueryParams().toString();
		});
	}

	loadTodos();
})();
