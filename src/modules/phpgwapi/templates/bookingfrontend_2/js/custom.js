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
		var selectedTemplate = $(this).val();
		var version;
		
		// Map template selection to version API format
		if (selectedTemplate === 'bookingfrontend') {
			version = 'original';
		} else if (selectedTemplate === 'bookingfrontend_2') {
			version = 'new';
		}
		
		// Use the new version API
		$.ajax({
			type: 'POST',
			dataType: 'json',
			contentType: 'application/json',
			data: JSON.stringify({ version: version }),
			url: '/bookingfrontend/version',
			success: function (data)
			{
				if (data && data.success) {
					location.reload(true);
				}
			}
		});
	});

	// Legacy compatibility with dropdown selector
	$("#template_selector").change(function ()
	{
		var selectedTemplate = $(this).val();
		var version;
		
		// Map template selection to version API format
		if (selectedTemplate === 'bookingfrontend') {
			version = 'original';
		} else if (selectedTemplate === 'bookingfrontend_2') {
			version = 'new';
		}
		
		// Use the new version API
		$.ajax({
			type: 'POST',
			dataType: 'json',
			contentType: 'application/json',
			data: JSON.stringify({ version: version }),
			url: '/bookingfrontend/version',
			success: function (data)
			{
				if (data && data.success) {
					location.reload(true);
				}
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

