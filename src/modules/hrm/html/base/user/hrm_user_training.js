(function () {
	'use strict';

	var config = window.__hrmTraining;
	if (!config || !window.AppDatatable) return;

	AppDatatable.init({
		id: config.id,
		ajax: {url: config.apiUrl, method: 'GET'},
		serverSide: true,
		autoWidth: false,
		columns: [
			{data: 'category', title: config.lang.category},
			{data: 'title', title: config.lang.title},
			{data: 'place', title: config.lang.place},
			{data: 'credits', title: config.lang.credits},
			{data: 'start_date', title: config.lang.startDate},
			{data: 'end_date', title: config.lang.endDate}
		],
		order: [[4, 'desc']],
		lang: {emptyTable: config.lang.emptyTable}
	});
})();
