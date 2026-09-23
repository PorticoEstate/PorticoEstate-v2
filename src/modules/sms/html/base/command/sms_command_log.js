(function ()
{
	'use strict';

	var config = window.__smsCommandLog;
	if (!config || !window.AppDatatable) return;

	function escapeHtml(value)
	{
		return String(value === undefined || value === null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function renderParam(value, type, row)
	{
		if (type !== 'display' || !row.redirect_url) return escapeHtml(value);
		return '<a href="' + escapeHtml(row.redirect_url) + '">' + escapeHtml(value) + '</a>';
	}

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl },
		serverSide: true,
		autoWidth: false,
		pageLength: config.pageLength,
		lengthMenu: config.lengthMenu,
		columns: [
			{ data: 'id', title: config.lang.id },
			{ data: 'code', title: config.lang.code },
			{ data: 'sender', title: config.lang.sender },
			{ data: 'success', title: config.lang.success },
			{ data: 'datetime', title: config.lang.datetime },
			{ data: 'param', title: config.lang.param, render: renderParam }
		],
		order: [[0, 'desc']]
	});
})();
