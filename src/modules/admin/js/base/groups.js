
get_user_data = function ()
{
	if (set_user_data === 0)
	{
		var includedApi = oTable1.api();
		includedApi.ajax.url(link_included_user_items).load();

		var notIncludedApi = oTable2.api();
		notIncludedApi.settings()[0].ajax = { url: link_not_included_user_items, data: {}, type: 'GET' };
		notIncludedApi.ajax.url(link_not_included_user_items).load();

		set_user_data = 1;
	}
};

getRequestData = function (dataSelected, parameters)
{

	var data = {};

	$.each(parameters.parameter, function (i, val)
	{
		data[val.name] = {};
	});

	var n = 0;
	for (var n = 0; n < dataSelected.length; ++n)
	{
		$.each(parameters.parameter, function (i, val)
		{
			data[val.name][n] = dataSelected[n][val.source];
		});
	}

	return data;
};


addUser = function (oArgs, parameters)
{

	var selectedRows = oTable2.api().rows({ selected: true });
	var nTable = 1;

	if (selectedRows.count() === 0)
	{
		alert('None selected');
		return false;
	}

	var selected = selectedRows.data();
	var data = getRequestData(selected, parameters);
	var requestUrl = phpGWLink('index.php', oArgs);

	JqueryPortico.execute_ajax(requestUrl, function (result)
	{

		//	JqueryPortico.show_message(nTable, result);

		oTable1.api().draw();
		oTable2.api().draw();

	}, data, 'POST', 'JSON');
};

removeUser = function (oArgs, parameters)
{

	var selectedRows = oTable1.api().rows({ selected: true });
	var nTable = 1;

	if (selectedRows.count() === 0)
	{
		alert('None selected');
		return false;
	}

	var selected = selectedRows.data();
	var data = getRequestData(selected, parameters);
	var requestUrl = phpGWLink('index.php', oArgs);

	JqueryPortico.execute_ajax(requestUrl, function (result)
	{

	//		JqueryPortico.show_message(nTable, result);

		oTable1.api().draw();
		oTable2.api().draw();

	}, data, 'POST', 'JSON');
};

