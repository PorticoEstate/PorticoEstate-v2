(function () {
	'use strict';

	var CFG = window.__documentList;
	var L = CFG.lang;

	var rowActions = [];
	if (CFG.canWrite) {
		rowActions.push({
			type: 'link',
			url: '/booking/view/buildings/documents/{id}/edit',
			label: L.edit,
			variant: 'secondary'
		});
	}
	if (CFG.canDelete) {
		rowActions.push({
			type: 'delete',
			url: '/booking/buildings/{owner_id}/documents/{id}',
			label: L.delete,
			variant: 'tertiary',
			confirm: L.confirmDelete,
			successMessage: L.deleted
		});
	}

	AppDatatable.init({
		id: 'app-datatable',
		newItem: CFG.canCreate ? {label: L.addDocument, url: CFG.addDocumentUrl} : undefined,
		ajax: {url: '/booking/buildings/documents'},
		columns: [
			{
				data: 'name',
				title: L.document,
				render: AppDatatable.render.link({
					url: '/booking/buildings/documents/{id}/download',
					target: '_blank'
				})
			},
			{data: 'owner_name', title: L.building},
			{data: 'description', title: L.description},
			{
				data: 'category',
				title: L.category,
				render: AppDatatable.render.lookup({
					source: '/booking/buildings/documents/categories'
				})
			}
		],
		rowActions: rowActions.length ? rowActions : undefined,
		order: [[0, 'asc']],
		pageLength: 25,
		lang: {
			emptyTable: L.emptyTable
		}
	});
})();
