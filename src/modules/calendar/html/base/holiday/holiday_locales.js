(function ()
{
	'use strict';
	var config = window.__calendarHolidayLocales;
	if (!config || !window.AppDatatable) return;

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl },
		newItem: { label: config.lang.add, url: config.newUrl },
		columns: [{ data: 'locale', title: config.lang.locale }],
		rowActions: [
			{ type: 'link', label: config.lang.view, url: config.viewUrl },
			{ type: 'delete', label: config.lang.delete, url: config.deleteUrl, confirm: config.lang.confirm }
		],
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[0, 'asc']],
		pageLength: 10,
		lang: { search: config.lang.search, emptyTable: config.lang.empty }
	});
})();
