$(document).ready(function ()
{
	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'booking.uiorganization.index', filter_active: 1}, true),
		'field_organization_name', 'field_organization_id', 'organization_container');
});
