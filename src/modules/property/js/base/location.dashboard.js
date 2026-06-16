
function buildDashboardProjectEditUrl(projectId)
{
	return phpGWLink('index.php', {
		menuaction: 'property.uiproject.edit',
		id: projectId
	});
}

var project_link = function (key, oData)
{
	if (oData[key] > 0)
	{
		return "<a href=" + buildDashboardProjectEditUrl(oData[key]) + ">" + oData[key] + "</a>";
	}
};

var ticket_link = function (key, oData)
{
	if (oData[key] > 0)
	{
		let url = phpGWLink('index.php', {
			menuaction: 'property.uitts.view',
			id: oData[key]
		});
		return "<a href=" + url + ">" + oData[key] + "</a>";
	}
}