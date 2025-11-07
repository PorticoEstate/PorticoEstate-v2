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

	var ssnInput = $('#ssn');

	function isValidNorwegianSSN(value)
	{
		var ssn = (value || '').replace(/\D/g, '');
		if (ssn.length !== 11)
		{
			return false;
		}

		var digits = ssn.split('').map(function(digit) {
			return parseInt(digit, 10);
		});

		var weights1 = [3, 7, 6, 1, 8, 9, 4, 5, 2];
		var weights2 = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

		var sum1 = 0;
		for (var i = 0; i < weights1.length; i++)
		{
			sum1 += digits[i] * weights1[i];
		}
		var control1 = 11 - (sum1 % 11);
		if (control1 === 11)
		{
			control1 = 0;
		}
		if (control1 === 10 || control1 !== digits[9])
		{
			return false;
		}

		var sum2 = 0;
		for (var j = 0; j < weights2.length; j++)
		{
			sum2 += digits[j] * weights2[j];
		}
		var control2 = 11 - (sum2 % 11);
		if (control2 === 11)
		{
			control2 = 0;
		}
		if (control2 === 10 || control2 !== digits[10])
		{
			return false;
		}

		return true;
	}

	function updateSSNValidity()
	{
		var value = ssnInput.val();
		if (!value)
		{
			if (ssnInput[0] && typeof ssnInput[0].setCustomValidity === 'function')
			{
				ssnInput[0].setCustomValidity('');
			}
			return true;
		}

		var isValid = isValidNorwegianSSN(value);
		if (ssnInput[0] && typeof ssnInput[0].setCustomValidity === 'function')
		{
			ssnInput[0].setCustomValidity(isValid ? '' : 'Invalid SSN (Mod 11 check failed).');
		}
		return isValid;
	}




	ssnInput.on('input blur', updateSSNValidity);

	$('#form').on('submit', function(event){
		if (!updateSSNValidity())
		{
			event.preventDefault();
			ssnInput.focus();
		}
	});

		//trigger autoNumeric
	var	anElement = new AutoNumeric('.amount',{
		caretPositionOnFocus: "decimalRight",
		decimalCharacter: ",",
		digitGroupSeparator: " "
	});





});