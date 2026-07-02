(function () {
	var root = document.getElementById('todo-app');
	if (!root) {
		return;
	}

	var apiUrl = root.dataset.apiUrl;
	var viewUrl = root.dataset.viewUrl;
	var csvUrl = root.dataset.csvUrl;
	var loadingEl = document.getElementById('todo-loading');
	var errorEl = document.getElementById('todo-error');
	var tableEl = document.getElementById('todo-table');
	var tbodyEl = document.getElementById('todo-table-body');
	var searchEl = document.getElementById('todo-search');
	var filterEl = document.getElementById('todo-filter');
	var catEl = document.getElementById('todo-cat');
	var csvBtn = document.getElementById('todo-csv');
	var paginationEl = document.getElementById('todo-pagination');
	var paginationSummaryEl = document.getElementById('todo-pagination-summary');
	var pageStatusEl = document.getElementById('todo-page-status');
	var pageSizeEl = document.getElementById('todo-page-size');
	var prevBtn = document.getElementById('todo-page-prev');
	var nextBtn = document.getElementById('todo-page-next');
	var initialParams = new URLSearchParams(window.location.search);
	var initialCatId = initialParams.get('cat_id') || '0';
	var defaultPageSize = parseInt(root.dataset.defaultPageSize || '25', 10) || 25;
	var pageSize = defaultPageSize;
	var currentPage = Math.max(parseInt(initialParams.get('page') || '1', 10) || 1, 1);
	var totalItems = 0;

	if (initialParams.get('filter')) {
		filterEl.value = initialParams.get('filter');
	}

	if (initialParams.get('search')) {
		searchEl.value = initialParams.get('search');
	}

	if (catEl && initialCatId) {
		catEl.value = initialCatId;
	}

	if (pageSizeEl) {
		var initialLimit = parseInt(initialParams.get('limit') || String(defaultPageSize), 10);
		var hasMatchingOption = Array.prototype.some.call(pageSizeEl.options, function (option) {
			return parseInt(option.value, 10) === initialLimit;
		});

		if (hasMatchingOption) {
			pageSize = initialLimit;
		}

		pageSizeEl.value = String(pageSize);
	}

	function makeActionLink(url, label) {
		if (!url) {
			return '&nbsp;';
		}
		return '<a href="' + escapeAttribute(url) + '">' + escapeHtml(label) + '</a>';
	}

	function escapeAttribute(value) {
		return String(value == null ? '' : value)
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/'/g, '&#039;');
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

	function setState(state, errorMessage) {
		loadingEl.hidden = state !== 'loading';
		errorEl.hidden = state !== 'error';
		tableEl.hidden = state !== 'ready';
		if (paginationEl) {
			paginationEl.hidden = state !== 'ready';
		}
		if (state === 'error') {
			errorEl.textContent = errorMessage || root.dataset.langError;
		}
	}

	function updateBrowserUrl() {
		var params = new URLSearchParams();
		if (searchEl.value) {
			params.set('search', searchEl.value);
		}
		if (filterEl.value && filterEl.value !== 'none') {
			params.set('filter', filterEl.value);
		}
		if (catEl && catEl.value && catEl.value !== '0') {
			params.set('cat_id', catEl.value);
		}
		if (currentPage > 1) {
			params.set('page', String(currentPage));
		}
		if (pageSize !== defaultPageSize) {
			params.set('limit', String(pageSize));
		}

		var query = params.toString();
		var url = window.location.pathname + (query ? '?' + query : '');
		window.history.replaceState(null, '', url);
	}

	function renderPagination(itemCount) {
		if (!paginationEl || !paginationSummaryEl || !pageStatusEl || !prevBtn || !nextBtn) {
			return;
		}

		var totalPages = Math.max(Math.ceil(totalItems / pageSize), 1);
		var startItem = totalItems === 0 ? 0 : ((currentPage - 1) * pageSize) + 1;
		var endItem = totalItems === 0 ? 0 : startItem + itemCount - 1;

		paginationSummaryEl.textContent = (root.dataset.langShowing || 'Showing') + ' ' + startItem + '-' + endItem + ' / ' + totalItems;
		pageStatusEl.textContent = (root.dataset.langPage || 'Page') + ' ' + currentPage + ' ' + (root.dataset.langOf || 'of') + ' ' + totalPages;
		prevBtn.disabled = currentPage <= 1;
		nextBtn.disabled = currentPage >= totalPages || totalItems === 0;
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
				'<td><a href="' + escapeAttribute(href) + '">' + escapeHtml(item.title || '') + '</a></td>' +
				'<td>' + escapeHtml(item.status || '') + '</td>' +
				'<td>' + escapeHtml(item.pri || '') + '</td>' +
				'<td>' + escapeHtml(item.sdate || '') + '</td>' +
				'<td>' + escapeHtml(item.edate || '') + '</td>' +
				'<td>' + escapeHtml(item.owner || '') + '</td>' +
				'<td>' + renderMultiline(item.assigned || '') + '</td>' +
				'<td>' + makeActionLink(actions.view, root.dataset.langView || 'View') + '</td>' +
				'<td>' + makeActionLink(actions.edit, root.dataset.langEdit || 'Edit') + '</td>' +
				'<td>' + makeActionLink(actions.delete, root.dataset.langDelete || 'Delete') + '</td>' +
				'<td>' + makeActionLink(actions.subadd, root.dataset.langAddSub || 'Add Sub Project') + '</td>';
			tbodyEl.appendChild(row);
		});
	}

	function buildQueryParams() {
		var params = buildFilterParams();
		params.set('start', String((currentPage - 1) * pageSize));
		params.set('limit', String(pageSize));
		params.set('sort', 'created');
		params.set('dir', 'DESC');
		return params;
	}

	function buildFilterParams() {
		var params = new URLSearchParams();
		params.set('filter', filterEl.value || 'none');
		params.set('search', searchEl.value || '');
		if (catEl && catEl.value) {
			params.set('cat_id', catEl.value);
		}
		return params;
	}

	function buildRequestUrl(baseUrl, queryParams) {
		var url = new URL(baseUrl, window.location.origin);
		queryParams.forEach(function (value, key) {
			url.searchParams.set(key, value);
		});
		return url.toString();
	}

	function loadTodos() {
		setState('loading');

		var params = buildQueryParams();

		fetch(buildRequestUrl(apiUrl, params), {
			credentials: 'same-origin'
		})
			.then(function (res) {
				if (!res.ok) {
					throw new Error('HTTP ' + res.status);
				}
				return res.json();
			})
			.then(function (payload) {
				totalItems = parseInt(payload.total || 0, 10) || 0;
				var totalPages = Math.max(Math.ceil(totalItems / pageSize), 1);
				if (currentPage > totalPages) {
					currentPage = totalPages;
					loadTodos();
					return;
				}

				renderRows(payload.items || []);
				renderPagination((payload.items || []).length);
				updateBrowserUrl();
				setState('ready');
			})
			.catch(function (err) {
				setState('error', err.message);
			});
	}

	var searchTimer = null;
	searchEl.addEventListener('input', function () {
		currentPage = 1;
		if (searchTimer) {
			clearTimeout(searchTimer);
		}
		searchTimer = setTimeout(loadTodos, 250);
	});

	filterEl.addEventListener('change', function () {
		currentPage = 1;
		loadTodos();
	});

	if (catEl) {
		catEl.addEventListener('change', function () {
			currentPage = 1;
			loadTodos();
		});
	}

	if (pageSizeEl) {
		pageSizeEl.addEventListener('change', function () {
			var selectedSize = parseInt(pageSizeEl.value || String(defaultPageSize), 10) || defaultPageSize;
			pageSize = selectedSize;
			currentPage = 1;
			loadTodos();
		});
	}

	if (prevBtn) {
		prevBtn.addEventListener('click', function () {
			if (currentPage <= 1) {
				return;
			}

			currentPage -= 1;
			loadTodos();
		});
	}

	if (nextBtn) {
		nextBtn.addEventListener('click', function () {
			var totalPages = Math.max(Math.ceil(totalItems / pageSize), 1);
			if (currentPage >= totalPages) {
				return;
			}

			currentPage += 1;
			loadTodos();
		});
	}

	if (csvBtn) {
		csvBtn.addEventListener('click', function () {
			window.location.href = buildRequestUrl(csvUrl, buildFilterParams());
		});
	}

	loadTodos();
})();
