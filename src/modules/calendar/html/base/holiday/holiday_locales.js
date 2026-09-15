(function ()
{
	'use strict';
	var config = window.__calendarHolidayLocales;
	if (!config || !window.AppDatatable) return;

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl, method: 'GET' },
		serverSide: true,
		newItem: { label: config.lang.add, url: config.newUrl },
		filters: [{
			name: 'locale',
			label: config.lang.locale,
			type: 'select',
			options: [{ value: '', label: config.lang.all }].concat((config.locales || []).map(function (locale)
			{
				return { value: locale, label: locale };
			}))
		}],
		columns: [
			{ data: 'locale', title: config.lang.locale },
			{ data: 'holiday_count', title: config.lang.holidays, searchable: false }
		],
		rowActions: [
			{ type: 'link', label: config.lang.view, url: config.viewUrl },
			{ type: 'delete', label: config.lang.delete, url: config.deleteUrl, confirm: config.lang.confirm }
		],
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[0, 'asc']],
		pageLength: 10,
		lang: { search: config.lang.search, emptyTable: config.lang.empty }
	});
})();
