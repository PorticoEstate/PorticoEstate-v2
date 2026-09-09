(function () {
	'use strict';

	var config = window.__hrmUsers;
	if (!config || !window.AppDatatable) return;

	AppDatatable.init({
		id: config.id,
		ajax: {url: config.apiUrl, method: 'GET'},
		serverSide: true,
		autoWidth: false,
		columns: [
			{data: 'first_name', title: config.lang.firstName},
			{data: 'last_name', title: config.lang.lastName}
		],
		rowActions: [
			{
				type: 'link',
				label: config.lang.training,
				url: config.trainingUrlTemplate,
				visible: function (row) { return !!row.can_training; }
			}
		],
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[1, 'asc']],
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
