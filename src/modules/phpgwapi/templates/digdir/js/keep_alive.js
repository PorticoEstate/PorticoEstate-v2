
function keepAlive()
{
	var oArgs = {keep_alive: true};
	var keep_alive_url = phpGWLink('home/', oArgs, true);

	$.ajax({
		cache: false,
		contentType: false,
		processData: false,
		type: 'GET',
		url: keep_alive_url,
		success: function (data, textStatus, jqXHR)
		{
			if (data)
			{
				if ( data.status !== 200)
				{
					//something...
				}
			}
		},
		error: function (XMLHttpRequest, textStatus, errorThrown)
		{
			//Test...
//			clearInterval(refreshIntervalId);
//			alert('expired');
		}
	});
}

var refreshIntervalId = setInterval(keepAlive, 60000);  //every minute