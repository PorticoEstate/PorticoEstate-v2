/* global lang, event_id */

var building_id_selection = "";
$(document).ready(function ()
{

	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'booking.uibuilding.index'}, true),
		'field_building_name', 'field_building_id', 'building_container');

	$("#field_activities").select2({
			placeholder: lang['Activities'],
			width: '100%'
		});

		$('#field_activities').on('select2:open', function (e) {

			$(".select2-search__field").each(function()
			{
				if ($(this).attr("aria-controls") == 'select2-field_activities-results')
				{
					$(this)[0].focus();
				}
			});
		});		
	
	//on change of the select field_entities, look up category and set field_category options accordingly
	$("#field_entities").on("change", function (event)
	{
		var entity_id = $(this).val();
		if (entity_id)
		{
			var url = phpGWLink('index.php', {menuaction: 'booking.uiresource_activity_entityform.get_categories', entity_id: entity_id}, true);
			$.getJSON(url, function (data)
			{
				var options = '<option value="">' + lang['Select'] + '</option>';
				$.each(data, function (index, category)
				{
					options += '<option value="' + category.location_id + '">' + category.name + '</option>';
				});
				$("#field_category").html(options);
			});
		}
		else
		{
			$("#field_category").html('<option value="">' + lang['Select'] + '</option>');
		}
	});
	
});

$(window).on('load', function ()
{
	var building_id = $('#field_building_id').val();
	if (building_id)
	{
		populateTableChkResources(building_id, initialSelection);
		building_id_selection = building_id;
	}
	$("#field_building_name").on("autocompleteselect", function (event, ui)
	{
		var building_id = ui.item.value;
		if (building_id != building_id_selection)
		{
			populateTableChkResources(building_id, []);
			building_id_selection = building_id;
		}
	});
	

	$('#resources_container').on('change', '.chkRegulations', function ()
	{
		var resources = new Array();
		$('#resources_container input[name="resources[]"]:checked').each(function ()
		{
			resources.push($(this).val());
		});

	});


});

if ($.formUtils)
{

	$.formUtils.addValidator({
		name: 'application_resources',
		validatorFunction: function (value, $el, config, language, $form)
		{
			var n = 0;
			$('#resources_container table input[name="resources[]"]').each(function ()
			{
				if ($(this).is(':checked'))
				{
					n++;
				}
			});
			var v = (n > 0) ? true : false;

			if(!v)
			{
				$('#resources_container').find('table').addClass("error").css("border-color", "red");
			}
			else
			{
				$('#resources_container').find('table').removeClass("error").css("border-color", "");
			}

			return v;
		},
		errorMessage: 'Please choose at least 1 resource',
		errorMessageKey: 'application_resources'
	});
}

function populateTableChkResources(building_id, selection)
{
	var url = phpGWLink('index.php', {menuaction: 'booking.uiresource.index', sort: 'name', filter_building_id: building_id, length: -1}, true);
	var container = 'resources_container';
	var colDefsResources = [{label: '', object: [{type: 'input', attrs: [
						{name: 'type', value: 'checkbox'}, {name: 'name', value: 'resources[]'}, {name: 'class', value: 'chkRegulations'}
					]}
			], value: 'id', checked: selection}, {key: 'name', label: lang['Name']}, {key: 'rescategory_name', label: lang['Resource Type']}
	];
	populateTableChk(url, container, colDefsResources);
}

function populateTableChk(url, container, colDefs)
{
	createTable(container, url, colDefs, '', 'pure-table pure-table-bordered');
}
