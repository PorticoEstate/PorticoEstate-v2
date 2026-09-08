(function () {
	'use strict';

	var config = window.__smsInbox;
	if (!config || !window.AppDatatable) return;

	var rowActions = [
		{
			type: 'link',
			label: config.lang.reply,
			url: config.replyUrlTemplate
		}
	];
	if (config.canDelete) {
		rowActions.push({
			type: 'link',
			label: config.lang.delete,
			url: config.deleteUrlTemplate,
			variant: 'tertiary'
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
			{data: 'sender', title: config.lang.sender},
			{data: 'user', title: config.lang.user, searchable: false},
			{data: 'message', title: config.lang.message}
		],
		rowActions: rowActions,
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
