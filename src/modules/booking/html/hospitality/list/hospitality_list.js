(function () {
	'use strict';

	var CFG = window.__hospitalityList;
	var L = CFG.lang;

	var columns = [
		{
			data: 'id',
			title: 'ID',
			className: 'app-datatable__col-id'
		},
		{
			data: 'name',
			title: L.name
		},
		{
			data: 'resource_name',
			title: L.resource_name
		},
		{
			data: 'active',
			title: L.active,
			render: function (data, type) {
				if (type !== 'display') return data;
				return data == 1 || data === true ? L.yes : L.no;
			}
		},
		{
			data: 'remote_serving_enabled',
			title: L.remote_serving,
			render: function (data, type) {
				if (type !== 'display') return data;
				return data == 1 || data === true ? L.yes : L.no;
			}
		},
		{
			data: 'allow_delivery',
			title: L.allow_delivery,
			render: function (data, type) {
				if (type !== 'display') return data;
				return data == 1 || data === true ? L.yes : L.no;
			}
		}
	];

	var filters = [
		{
			name: 'active',
			label: L.active,
			type: 'select',
			column: 3,
			options: [
				{value: '', label: L.all},
				{value: '1', label: L.yes},
				{value: '0', label: L.no}
			]
		}
	];

	var rowActions = [];
	if (CFG.permissions.write) {
		rowActions.push({
			type: 'link',
			url: CFG.editBaseUrl + '{id}',
			label: L.edit,
			variant: 'secondary'
		});
	}
AppDatatable.init({
		id: 'app-datatable',
		newItem: CFG.permissions.create
			? {label: L.add + ' ' + L.name, url: CFG.editBaseUrl + 'add'}
			: undefined,
		ajax: {url: CFG.dataUrl, dataSrc: ''},
		columns: columns,
		filters: filters,
		rowActions: rowActions.length ? rowActions : undefined,
		order: [[0, 'asc']],
		pageLength: 25,
		lang: {
			emptyTable: L.emptyTable
		}
	});
})();
