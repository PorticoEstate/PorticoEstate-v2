


$(document).ready(function ()
{
	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'property.bolocation.get_locations', level: 4, get_tenant_name: true}, true),	'location_name', 'location_code', 'location_container');

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