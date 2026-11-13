open_monthly = function (part_of_town_id, year, month)
{
	var oArgs = {menuaction: 'controller.uicalendar_planner.monthly', year: year,
		month: month,
		part_of_town_id: part_of_town_id,
		control_id: $("#control_id").val(),
		control_area_id: $("#control_area_id").val(),
		entity_group_id: $("#entity_group_id").val()
	};
	var requestUrl = phpGWLink('index.php', oArgs);
	location = requestUrl;
};

$(function ()
{
	$("#control_area_id").on("change", function ()
	{
		var control_area_id = $(this).val();
		var oArgs = {menuaction: 'controller.uicontrol.get_controls_by_control_area'};
		var requestUrl = phpGWLink('index.php', oArgs, true);

		//  	$("#hidden_control_area_id").val( control_area_id );
		//     var control_id_init = $("#hidden_control_id").val();
		var htmlString = "";
		$.ajax({
			type: 'POST',
			dataType: 'json',
			url: requestUrl + "&control_area_id=" + control_area_id,
			success: function (data)
			{
				if (data != null)
				{
					htmlString = "<option>Velg kontroll</option>"
					var obj = JSON.parse(data);

					$.each(obj, function (i)
					{
						var selected = '';
						var title = obj[i].title;
						htmlString += "<option value='" + obj[i].id + "'" + selected + ">" + obj[i].title + "</option>";
					});

					$("#control_id").html(htmlString);
				}
				else
				{
					htmlString += "<option>Ingen kontroller</option>"
					$("#control_id").html(htmlString);
//						$("#hidden_control_id").val(-1); //reset
				}
			}
		});

	});


	$("#part_of_town_id").porticoMultiselect().on('select2:close', function ()
	{
		$("#form").trigger('submit');
	});

//	$("#part_of_town_id").on("change", function ()
//	{
//		$("#form").submit();
//	});


});



