(function () {
	'use strict';

	var config = window.__todoList;
	if (!config || !window.AppDatatable) return;

	function escapeHtml(value) {
		return String(value === undefined || value === null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function renderTitle(value, type, row) {
		if (type !== 'display') return value || '';
		var level = Math.max(0, parseInt(row.level || 0, 10) || 0);
		var marker = level > 0
			? '<span class="todo-title__marker">' + '&#9500;'.repeat(Math.min(level, 4)) + '</span>'
			: '';
		return '<span class="todo-title" style="margin-left:' + (level * 1.1) + 'rem">'
			+ marker + escapeHtml(value) + '</span>';
	}

	function renderAssigned(value, type) {
		if (type !== 'display') return value || '';
		var entries = Array.isArray(value) ? value : [];
		var names = entries
			.map(function (entry) { return entry && entry.name ? entry.name : ''; })
			.filter(Boolean);
		return names.length ? names.map(escapeHtml).join('<br>') : '-';
	}

	function appendQuery(url, params) {
		var search = Object.keys(params)
			.filter(function (key) { return params[key] !== undefined && params[key] !== null && params[key] !== ''; })
			.map(function (key) { return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]); })
			.join('&');
		return search ? url + (url.indexOf('?') === -1 ? '?' : '&') + search : url;
	}

	var rowActions = [
		{type: 'link', label: config.lang.view, url: config.viewUrlTemplate},
		{type: 'link', label: config.lang.edit, url: config.editUrlTemplate},
		{
			type: 'custom',
			label: config.lang.addSubProject,
			handler: function (row) {
				window.location.href = appendQuery(config.addUrl, {parent: row.id, cat_id: row.cat_id});
			}
		}
	];
	if (config.canDelete) {
		rowActions.push({type: 'link', label: config.lang.delete, url: config.deleteUrlTemplate, variant: 'tertiary'});
	}

	AppDatatable.init({
		id: config.id,
		ajax: {url: config.dataUrl},
		serverSide: true,
		newItem: {label: config.lang.add, url: config.addUrl},
		buttons: [{label: config.lang.matrix, url: config.matrixUrl, attr: {'data-variant': 'secondary', 'data-size': 'sm'}}],
		downloadUrl: config.csvUrl,
		columns: [
			{data: 'id', title: config.lang.id, searchable: false, render: AppDatatable.render.link({url: config.viewUrlTemplate})},
			{data: 'title', title: config.lang.title, render: renderTitle},
			{data: 'status', title: config.lang.status},
			{data: 'pri', title: config.lang.urgency, searchable: false},
			{data: 'sdate', title: config.lang.startDate, searchable: false},
			{data: 'edate', title: config.lang.endDate, searchable: false},
			{data: 'owner', title: config.lang.owner},
			{data: 'assigned_entries', title: config.lang.assigned, render: renderAssigned}
		],
		filters: [
			{
				name: 'cat_id',
				label: config.lang.category,
				type: 'select',
				options: config.categories.map(function (category) {
					return {value: category.id, label: category.name};
				})
			},
			{
				name: 'filter',
				label: config.lang.filter,
				type: 'select',
				options: config.filters.map(function (filter) {
					return {value: filter.id, label: filter.name};
				})
			}
		],
		rowActions: rowActions,
		rowActionsDisplay: 'contextMenu',
		initialSearch: config.initialSearch,
		order: [[0, 'asc']],
		pageLength: config.pageLength,
		lengthMenu: config.lengthMenu,
		lang: {
			search: config.lang.search,
			emptyTable: config.lang.emptyTable,
			info: config.lang.info,
			infoEmpty: config.lang.infoEmpty,
			infoFiltered: config.lang.infoFiltered,
			lengthMenu: config.lang.lengthMenu,
			zeroRecords: config.lang.zeroRecords,
			paginate: {
				first: config.lang.first,
				last: config.lang.last,
				next: config.lang.next,
				previous: config.lang.previous
			}
		},
		filterLang: {
			filter: config.lang.filter,
			resetFilter: config.lang.resetFilter,
			activeFilters: config.lang.activeFilters
		}
	});
})();
