(function ()
{
	'use strict';

	var config = window.__smsCommands;
	if (!config || !window.AppDatatable) return;

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl },
		serverSide: true,
		newItem: { label: config.lang.add, url: config.addUrl },
		columns: [
			{ data: 'code', title: config.lang.code },
			{ data: 'uid', title: config.lang.user },
			{ data: 'exec', title: config.lang.exec }
		],
		rowActions: [
			{ type: 'link', label: config.lang.edit, url: config.addUrl + '/{id}' },
			{ type: 'link', label: config.lang.delete, url: config.deleteUrlTemplate + '/{id}', variant: 'tertiary' }
		],
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		lang: { emptyTable: config.lang.empty }
	});
})();
