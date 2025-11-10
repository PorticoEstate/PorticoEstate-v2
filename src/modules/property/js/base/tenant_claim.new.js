var location_code_selection = "";

this.get_reskontro = function (location_code)
{

	var oArgs = {menuaction: 'property.uitenant_claim.get_reskontro', location_code: location_code};
	var requestUrl = phpGWLink('index.php', oArgs, true);

	$.ajax({
		type: 'POST',
		dataType: 'json',
		url: requestUrl,
		success: function (data)
		{
			if (data != null)
			{
				if (data.sessionExpired)
				{
					alert('Sesjonen er utløpt - du må logge inn på nytt');
					return;
				}

				$('#reskontro').empty();
				$('#reskontro').append($('<option/>', {
					value: '',
					text: '-- Velg reskontro --'
				}));

				var obj = data;

				$.each(obj, function (i)
				{
					$('#reskontro').append($('<option/>', {
						value: obj[i].reskontro_code,
						text: obj[i].name + " [" + obj[i].reskontro_code + "]::" + obj[i].innflyttetdato
					}));

				});

			}
		}
	}).done(function ()
	{
	});
};


$(document).ready(function ()
{
	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'property.bolocation.get_locations', level: 4, get_tenant_name: true}, true),	'location_name', 'location_code', 'location_container');

	$("#location_name").on("autocompleteselect", function (event, ui)
	{
		var location_code = ui.item.value;

		if (location_code !== location_code_selection)
		{
			location_code_selection = location_code;
		}
		get_reskontro(location_code);
	});



	//mark required fields
	$('#form').find('input[required], select[required], textarea[required]').each(function(){
		var label = $("label[for='" + $(this).attr('id') + "']");
		if(label.length)
		{
			label.addClass('required');
		}
	});


	//trigger autoNumeric
	var	anElement = new AutoNumeric('.amount',{
		caretPositionOnFocus: "decimalRight",
		decimalCharacter: ",",
		digitGroupSeparator: " "
	});


});