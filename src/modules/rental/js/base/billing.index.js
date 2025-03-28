function onCreateBilling()
{
	var contract_type = document.getElementById('contract_type').value;

	if(!contract_type)
	{
		alert('Velg ansvarsområde');
		return;
	}


	var oArgs = {menuaction: 'rental.uibilling.add', contract_type: contract_type};
	var sUrl = phpGWLink('index.php', oArgs);
	window.location = sUrl;
}

function formatterPrice(key, oData)
{
	var amount = $.number(oData[key], decimalPlaces, decimalSeparator, thousandsSeparator) + ' ' + currency_suffix;
	return amount;
}

function onCommit(requestUrl)
{
	$('.dataTables_processing').show();

	JqueryPortico.execute_ajax(requestUrl, function (result)
	{

		var htmlString = "";
		document.getElementById('message').innerHTML = '';

		if (typeof (result.message) !== 'undefined')
		{
			$.each(result.message, function (k, v)
			{
				htmlString += "<div class=\"msg_good\">";
				htmlString += v.msg;
				htmlString += '</div>';
			});
		}

		if (typeof (result.error) !== 'undefined')
		{
			$.each(result.error, function (k, v)
			{
				htmlString += "<div class=\"error\">";
				htmlString += v.msg;
				htmlString += '</div>';
			});
		}

		document.getElementById('message').innerHTML = htmlString;

		oTable.api().draw();

	}, '', "POST", "JSON");

	$('.dataTables_processing').hide();
}

function onDelete(requestUrl)
{
	$('.dataTables_processing').show();

	JqueryPortico.execute_ajax(requestUrl, function (result)
	{

		document.getElementById('message').innerHTML = '';

		if (typeof (result.message) !== 'undefined')
		{
			$.each(result.message, function (k, v)
			{
				document.getElementById('message').innerHTML = v.msg;
			});
		}

		if (typeof (result.error) !== 'undefined')
		{
			$.each(result.error, function (k, v)
			{
				document.getElementById('message').innerHTML = v.msg;
			});
		}

		oTable.api().draw();

	}, '', "POST", "JSON");

	$('.dataTables_processing').hide();
}