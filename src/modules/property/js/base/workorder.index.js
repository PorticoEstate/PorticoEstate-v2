function buildWorkorderProjectEditUrl(projectId)
{
	return phpGWLink('index.php', {
		menuaction: 'property.uiproject.edit',
		id: projectId
	});
}

linktToProject = function (key, oData)
{
	var id = oData[key];
	return '<a href="' + buildWorkorderProjectEditUrl(id) + '">' + id + '</a>';
};

linktToOrder = function (key, oData)
{
	var id = oData[key];
	var url = phpGWLink('index.php', {
		menuaction: 'property.uiworkorder.edit',
		id: id
	});
	return '<a href="' + url + '">' + id + '</a>';
};