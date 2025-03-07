

var project_link = function (key, oData)
{
	let sUrl_project = phpGWLink('index.php', { menuaction: 'property.uiproject.edit' });
	
	if (oData[key] > 0)
	{
		return "<a href=" + sUrl_project + "&id=" + oData[key] + ">" + oData[key] + "</a>";
	}
};

var ticket_link = function (key, oData)
{
	let sUrl_ticket = phpGWLink('index.php', { menuaction: 'property.uitts.view' });
	if (oData[key] > 0)
	{
		return "<a href=" + sUrl_ticket + "&id=" + oData[key] + ">" + oData[key] + "</a>";
	}
}