
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


	var select2Theme = $('link[href*="select2-bootstrap-5-theme"]').length ? 'bootstrap-5' : 'default';

	$("#part_of_town_id, #inspector_id").each(function ()
	{
		var $select = $(this);
		$select.select2({
			theme: select2Theme,
			language: "no",
			width: '100%',
			closeOnSelect: false,
			allowClear: true,
			placeholder: $('label[for="' + this.id + '"]').text().trim()
		});
		addSelectAllControls($select);
	});

	// Digdir/Portico do not load Bootstrap JS, so provide a native toggle for the filter panel.
	if (typeof bootstrap === 'undefined' || !bootstrap.Collapse)
	{
		var $panel = $("#democollapseBtn");
		var $toggle = $('[data-bs-target="#democollapseBtn"]');
		$panel.removeClass('collapse').prop('hidden', true);
		$toggle.removeAttr('data-bs-toggle').on('click', function ()
		{
			var expanded = $panel.prop('hidden');
			$panel.prop('hidden', !expanded);
			$toggle.attr('aria-expanded', String(expanded));
		});
	}
});

function addSelectAllControls($select)
{
	var $controls = $('<div class="select2-select-all mt-1 mb-2"></div>');
	var $all = $('<button type="button" class="pure-button pure-button-secondary btn btn-sm btn-outline-secondary"></button>')
		.text('Velg alle')
		.on('click', function ()
		{
			$select.find('option').prop('selected', true);
			$select.trigger('change');
		});
	var $none = $('<button type="button" class="pure-button pure-button-secondary btn btn-sm btn-outline-secondary ms-1"></button>')
		.text('Fjern alle')
		.on('click', function ()
		{
			$select.val(null).trigger('change');
		});
	$controls.append($all, $none).insertAfter($select.next('.select2-container'));
}



