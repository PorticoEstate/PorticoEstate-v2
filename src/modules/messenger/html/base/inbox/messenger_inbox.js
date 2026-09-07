(function () {
	'use strict';

	var config = window.__messengerInbox;
	if (!config || !window.AppDatatable) return;

	function escapeHtml(value) {
		return String(value === undefined || value === null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function renderMessageCell(key) {
		return function (value, type, row) {
			if (type !== 'display') return value || '';
			var text = escapeHtml(value);
			return row.status === 'N' ? '<strong>' + text + '</strong>' : text;
		};
	}

	AppDatatable.init({
		id: config.id,
		ajax: {url: config.dataUrl},
		serverSide: true,
		newItem: {
			label: config.lang.compose,
			url: config.composeUrl
		},
		columns: [
			{data: 'id', title: config.lang.id, searchable: false, render: renderMessageCell('id')},
			{data: 'date', title: config.lang.date, searchable: false, render: renderMessageCell('date')},
			{data: 'from', title: config.lang.from, render: renderMessageCell('from')},
			{data: 'subject', title: config.lang.subject, render: renderMessageCell('subject')},
			{data: 'status', title: config.lang.status, sortable: false, searchable: false, hidden: true},
			{data: 'status_text', title: config.lang.status, sortable: false, searchable: false, render: renderMessageCell('status_text')}
		],
		filters: [
			{
				name: 'status',
				label: config.lang.status,
				type: 'select',
				options: config.statuses.map(function (status) {
					return {value: status.id, label: status.name};
				})
			}
		],
		rowActions: [
			{
				type: 'link',
				label: config.lang.view,
				url: config.viewUrlTemplate
			},
			{
				type: 'link',
				label: config.lang.delete,
				url: config.deleteUrlTemplate,
				variant: 'tertiary'
			}
		],
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[0, 'desc']],
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