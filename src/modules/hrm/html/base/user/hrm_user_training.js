(function ()
{
	'use strict';

	var config = window.__hrmTraining;
	if (!config || !window.AppDatatable) return;

	var rowActions = [
		{
			type: 'link',
			label: config.lang.view,
			url: config.viewUrlTemplate
		}
	];
	if (config.canEdit)
	{
		rowActions.push({
			type: 'link',
			label: config.lang.edit,
			url: config.editUrlTemplate
		});
	}
	if (config.canDelete)
	{
		rowActions.push({
			type: 'link',
			label: config.lang.delete,
			url: config.deleteUrlTemplate,
			variant: 'tertiary'
		});
	}

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl, method: 'GET' },
		serverSide: true,
		autoWidth: false,
		newItem: config.canAdd ? { label: config.lang.add, url: config.newUrl } : undefined,
		buttons: [{
			label: config.lang.cv,
			url: config.cvUrl,
			target: '_blank'
		}],
		columns: [
			{ data: 'category', title: config.lang.category },
			{ data: 'title', title: config.lang.title },
			{ data: 'place', title: config.lang.place },
			{ data: 'credits', title: config.lang.credits },
			{ data: 'start_date', title: config.lang.startDate },
			{ data: 'end_date', title: config.lang.endDate }
		],
		rowActions: rowActions,
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[4, 'desc']],
		lang: { emptyTable: config.lang.emptyTable }
	});
})();
