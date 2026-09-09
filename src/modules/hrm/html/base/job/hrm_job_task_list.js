(function ()
{
	'use strict';

	var config = window.__hrmJobTasks;
	if (!config || !window.AppDatatable) return;

	var rowActions = [
		{ type: 'link', label: config.lang.view, url: config.viewUrlTemplate },
		{ type: 'link', label: config.lang.up, url: config.moveUpUrlTemplate },
		{ type: 'link', label: config.lang.down, url: config.moveDownUrlTemplate }
	];
	if (config.canEdit) rowActions.push({ type: 'link', label: config.lang.edit, url: config.editUrlTemplate });
	if (config.canDelete) rowActions.push({ type: 'link', label: config.lang.delete, url: config.deleteUrlTemplate, variant: 'tertiary' });

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl, method: 'GET' },
		serverSide: true,
		autoWidth: false,
		newItem: config.canAdd ? { label: config.lang.add, url: config.newUrl } : undefined,
		columns: [
			{ data: 'name', title: config.lang.name },
			{ data: 'descr', title: config.lang.descr },
			{ data: 'value_sort', title: config.lang.sorting, orderable: false, searchable: false }
		],
		rowActions: rowActions,
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[2, 'asc']],
		pageLength: config.pageLength,
		lengthMenu: config.lengthMenu,
		lang: {
			search: config.lang.search,
			emptyTable: config.lang.emptyTable
		}
	});
})();