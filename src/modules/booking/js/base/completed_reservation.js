var seasonFilterBuildingId = false;

function updateBuildingFilter(sType, aArgs)
{
	$('#filter_season_name').val('');
	$('#filter_season_id').val('');
	seasonFilterBuildingId = aArgs[2].id;
}

function clearBuildingFilter()
{
	seasonFilterBuildingId = false;
}

function requestWithBuildingFilter(sQuery)
{
	return sQuery + (seasonFilterBuildingId ? '&filter_building_id=' + seasonFilterBuildingId : '');
}

/**
 * In order to process selected rows, there is compiled a form with the seleted items
 * 
 */
/**
 * Process reservations with the specified action
 * @param {string} action - The menuaction endpoint
 */
function process_completed_reservations(action)
{
	var oArgs = {
		menuaction: action
	};
	var requestUrl = phpGWLink('index.php', oArgs);

	var form = document.createElement("form");
	form.setAttribute("method", 'POST');
	form.setAttribute("action", requestUrl);

	// Add filter fields
	var filterFields = {
		'building_id': $('#filter_building_id').val(),
		'building_name': $('#filter_building_name').val(),
		'season_id': $('#filter_season_id').val(),
		'season_name': $('#filter_season_name').val(),
		'from_': $('#filter_from').val(),
		'to_': $('#filter_to').val(),
		'prevalidate': 1
	};

	// Create input elements for each filter field
	for (var name in filterFields)
	{
		var input = document.createElement("input");
		input.setAttribute("type", "hidden");
		input.setAttribute("name", name);
		input.setAttribute("value", filterFields[name]);
		form.appendChild(input);
	}

	// Add checked items
	$(".mychecks:checked").each(function ()
	{
		var hiddenField = document.createElement("input");
		hiddenField.setAttribute("type", "hidden");
		hiddenField.setAttribute("name", 'process[]');
		hiddenField.setAttribute("value", $(this).val());
		form.appendChild(hiddenField);
	});

	document.body.appendChild(form);
	form.submit();
}

/**
 * Export selected completed reservations
 */
function export_completed_reservations()
{
	process_completed_reservations('booking.uicompleted_reservation_export.add');
}

/**
 * Archive selected completed reservations
 */
function archive_completed_reservations()
{
	process_completed_reservations('booking.uicompleted_reservation_export.archive');
}

