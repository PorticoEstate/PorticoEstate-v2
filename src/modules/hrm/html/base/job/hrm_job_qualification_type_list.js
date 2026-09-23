(function ()
{
	'use strict';

	var config = window.__hrmQualificationTypes;
	if (!config || !window.AppDatatable) return;

	var rowActions = [];
	if (config.canEdit) rowActions.push({ type: 'link', label: config.lang.edit, url: config.editUrlTemplate });

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl, method: 'GET' },
		serverSide: true,
		autoWidth: false,
		newItem: config.canAdd ? { label: config.lang.add, url: config.newUrl } : undefined,
		columns: [
			{ data: 'name', title: config.lang.name },
			{ data: 'descr', title: config.lang.descr }
		],
		rowActions: rowActions,
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[0, 'asc']],
		pageLength: config.pageLength,
		lang: { search: config.lang.search, emptyTable: config.lang.emptyTable }
	});
})();