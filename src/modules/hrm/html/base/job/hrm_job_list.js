(function ()
{
	'use strict';

	var config = window.__hrmJobs;
	if (!config || !window.AppDatatable) return;

	function countLink(template, count)
	{
		return function (data, type, row)
		{
			if (type !== 'display') return data;
			return '<a href="' + template.replace('{id}', encodeURIComponent(row.id)) + '">' + data + ' [' + count(row) + ']</a>';
		};
	}

	var rowActions = [
		{ type: 'link', label: config.lang.view, url: config.viewUrlTemplate },
		{ type: 'link', label: config.lang.qualification, url: config.qualificationUrlTemplate },
		{ type: 'link', label: config.lang.task, url: config.taskUrlTemplate }
	];
	if (config.canAdd) rowActions.push({ type: 'link', label: config.lang.addSub, url: config.addSubUrlTemplate });
	if (config.canEdit) rowActions.push({ type: 'link', label: config.lang.edit, url: config.editUrlTemplate });
	if (config.canDelete) rowActions.push({ type: 'link', label: config.lang.delete, url: config.deleteUrlTemplate, variant: 'tertiary' });

	AppDatatable.init({
		id: config.id,
		ajax: { url: config.apiUrl, method: 'GET' },
		serverSide: true,
		autoWidth: false,
		newItem: config.canAdd ? { label: config.lang.add, url: config.newUrl } : undefined,
		buttons: [
			{ label: config.lang.reset, url: config.resetUrl },
			{ label: config.lang.print, action: function () { document.getElementById('hrm-job-print-form').submit(); } }
		],
		columns: [
			{ data: 'name', title: config.lang.name },
			{ data: 'descr', title: config.lang.descr },
			{ data: 'id', title: config.lang.qualification, orderable: false, searchable: false, render: countLink(config.qualificationUrlTemplate, function (row) { return row.quali_count || 0; }) },
			{ data: 'id', title: config.lang.task, orderable: false, searchable: false, render: countLink(config.taskUrlTemplate, function (row) { return row.task_count || 0; }) },
			{ data: 'id', title: config.lang.print, orderable: false, searchable: false, render: function (id, type) { return type === 'display' ? '<input type="checkbox" name="values[select][]" value="' + id + '">' : id; } }
		],
		rowActions: rowActions,
		rowActionsDisplay: 'contextMenu',
		rowActionsToolbar: true,
		order: [[0, 'asc']],
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