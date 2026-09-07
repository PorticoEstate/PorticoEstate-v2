(function () {
	'use strict';

	var config = window.__notesList;
	if (!config || !window.AppDatatable) return;

	AppDatatable.init({
		id: config.id,
		ajax: {
			url: config.dataUrl,
			dataSrc: 'data'
		},
		serverSide: true,
		newItem: {
			label: config.lang.add,
			url: config.addUrl
		},
		columns: [
			{data: 'id', title: config.lang.id, searchable: false},
			{data: 'date', title: config.lang.date, searchable: false},
			{data: 'first', title: config.lang.content},
			{data: 'owner', title: config.lang.owner, searchable: false},
			{data: 'cat_name', title: config.lang.category, searchable: false},
			{data: 'access', title: config.lang.access, searchable: false}
		],
		filters: [
			{
				name: 'cat_id',
				label: config.lang.category,
				type: 'select',
				options: config.categories.map(function (category) {
					return {value: category.id, label: category.name};
				})
			},
			{
				name: 'filter',
				label: config.lang.filter,
				type: 'select',
				options: config.filters.map(function (filter) {
					return {value: filter.id, label: filter.name};
				})
			}
		],
		rowActions: [
			{
				type: 'link',
				label: config.lang.view,
				url: config.viewUrlTemplate
			},
			{
				type: 'link',
				label: config.lang.edit,
				url: config.editUrlTemplate
			},
			{
				type: 'link',
				label: config.lang.delete,
				url: config.deleteUrlTemplate,
				variant: 'tertiary'
			}
		],
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
		},
		filterLang: {
			filter: config.lang.filter,
			resetFilter: config.lang.resetFilter,
			activeFilters: config.lang.activeFilters
		}
	});
})();
