(function ()
{
	'use strict';
	var config = window.__calendarHolidayList;
	if (!config || !window.AppDatatable) return;

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl },
		columns: [
			{ data: 'name', title: config.lang.name },
			{
				data: 'mday', title: config.lang.rule, render: function (value, type, row)
				{
					if (type !== 'display') return value;
					return row.mday ? row.mday + '/' + row.month_num : row.occurence + '. ' + row.dow;
				}
			}
		],
		rowActions: [
			{ type: 'link', label: config.lang.view, url: config.editUrl.replace('__HOLIDAY_ID__', '{id}') },
			{ type: 'link', label: config.lang.edit, url: config.editUrl.replace('__HOLIDAY_ID__', '{id}') },
			{ type: 'delete', label: config.lang.delete, url: config.deleteUrl, confirm: config.lang.confirm }
		],
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[0, 'asc']],
		pageLength: 25,
		lang: { search: config.lang.search, emptyTable: config.lang.empty }
	});
})();
