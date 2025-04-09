/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */



$(document).ready(function ()
{
	if (document.getElementById("main-page"))
	{
		$('#headcon').removeClass('header_borderline');
	}

	// Handle template selection change
	$('input[name="select_template"]').change(function() {
		var template = $(this).val();
		var isBeta = template === 'beta';
		
		// When beta is selected, use bookingfrontend_2 template with beta cookie
		if (isBeta) {
			template = 'bookingfrontend_2';
		}
		
		var oArgs = {
			menuaction: 'bookingfrontend.preferences.set'
		};

		var requestUrl = phpGWLink('bookingfrontend/', oArgs, true);

		// Set the beta cookie if beta option was selected
		// Set the beta cookie - explicitly use strings "true"/"false" for compatibility
		document.cookie = "beta_client=" + (isBeta ? "true" : "false") + "; path=/; max-age=" + 60*60*24*365;

		$.ajax({
			type: 'POST',
			dataType: 'json',
			data: {template_set: template},
			url: requestUrl,
			success: function (data)
			{
		//		console.log(data);
				location.reload(true);
			}
		});
	});

	// Legacy compatibility with dropdown selector
	$("#template_selector").change(function ()
	{
		var template = $(this).val();
		var isBeta = template === 'beta';
		
		// When beta is selected, use bookingfrontend_2 template with beta cookie
		if (isBeta) {
			template = 'bookingfrontend_2';
		}
		
		var oArgs = {
			menuaction: 'bookingfrontend.preferences.set'
		};

		var requestUrl = phpGWLink('bookingfrontend/', oArgs, true);

		// Set the beta cookie if beta option was selected
		// Set the beta cookie - explicitly use strings "true"/"false" for compatibility
		document.cookie = "beta_client=" + (isBeta ? "true" : "false") + "; path=/; max-age=" + 60*60*24*365;

		$.ajax({
			type: 'POST',
			dataType: 'json',
			data: {template_set: template},
			url: requestUrl,
			success: function (data)
			{
		//		console.log(data);
				location.reload(true);
			}
		});
	});

});

$(window).scroll(function ()
{
	if (document.getElementById("main-page") && $(window).scrollTop() < 10)
	{
		$('#headcon').removeClass('header_borderline');
	}
	else
	{
		$('#headcon').addClass('header_borderline');
	}
});

