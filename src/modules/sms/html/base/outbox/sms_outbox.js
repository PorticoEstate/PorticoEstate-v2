(function () {
	'use strict';

	var config = window.__smsOutbox;
	if (!config || !window.AppDatatable) return;

	var rowActions = [];
	if (config.canDelete) {
		rowActions.push({
			type: 'delete',
			label: config.lang.delete,
			url: config.deleteUrlTemplate,
			variant: 'tertiary',
			confirm: config.lang.confirmDelete,
			successMessage: config.lang.deleted
		});
	}

	AppDatatable.init({
		id: config.id,
		ajax: {url: config.dataUrl},
		serverSide: true,
		newItem: config.canSend ? {label: config.lang.send, url: config.sendUrl} : undefined,
		columns: [
			{data: 'id', title: config.lang.id, searchable: false},
			{data: 'date', title: config.lang.date, searchable: false, defaultContent: ''},
			{data: 'receiver', title: config.lang.receiver},
			{data: 'user', title: config.lang.user, searchable: false},
			{data: 'dst_group', title: config.lang.group, searchable: false},
			{data: 'status', title: config.lang.status, searchable: false},
			{data: 'message', title: config.lang.message}
		],
		rowActions: rowActions.length ? rowActions : undefined,
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[1, 'desc']],
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
		}
	});
})();
