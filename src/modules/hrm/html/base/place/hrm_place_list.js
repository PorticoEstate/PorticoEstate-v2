(function ()
{
	'use strict';

	var config = window.__hrmPlaces;
	if (!config || !window.AppDatatable) return;

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl, method: 'GET' },
		serverSide: true,
		autoWidth: false,
		newItem: { label: config.lang.add, url: config.newUrl },
		columns: [
			{ data: 'name', title: config.lang.name }
		],
		rowActions: [
			{ type: 'link', label: config.lang.view, url: config.viewUrlTemplate },
			{ type: 'link', label: config.lang.edit, url: config.editUrlTemplate },
			{ type: 'link', label: config.lang.delete, url: config.deleteUrlTemplate, variant: 'tertiary' }
		],
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
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
		}
	});
})();