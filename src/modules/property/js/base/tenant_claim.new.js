var location_code_selection = "";

var pendingList = 0;
var redirect_action;
var file_count = 0;

this.confirm_session = function (action)
{
	if (action == 'cancel')
	{
		window.location.href = phpGWLink('index.php', {menuaction: 'property.uitenant_claim.index'});
		return;
	}

	if (action == 'save' || action == 'apply')
	{
		conf = {
			modules: 'location, date, security, file',
			validateOnBlur: false,
			scrollToTopOnError: true,
			errorMessagePosition: 'top'
		};
		var test = $('form').isValid(false, conf);
		if (!test)
		{
			return;
		}
		
		// Check if at least one file has been selected
		if (pendingList === 0)
		{
			alert('Du må laste opp minst én fil');
			return;
		}
	}
	/**
	 * Block doubleclick
	 */
	var send_buttons = $('.pure-button');
	$(send_buttons).each(function ()
	{
		$(this).prop('disabled', true);
	});

	var oArgs = {menuaction: 'property.bocommon.confirm_session'};
	var strURL = phpGWLink('index.php', oArgs, true);

	$.ajax({
		type: 'POST',
		dataType: 'json',
		url: strURL,
		success: function (data)
		{
			if (data != null)
			{
				if (data['sessionExpired'] == true)
				{
					window.alert('sessionExpired - please log in');
					JqueryPortico.lightboxlogin();//defined in common.js
				}
				else
				{
					var form = document.getElementById('form');
					$('<div id="spinner" class="d-flex align-items-center">')
						.append($('<strong>').text('Lagrer...'))
						.append($('<div class="spinner-border ml-auto" role="status" aria-hidden="true"></div>')).insertAfter(form);
					window.scrollBy(0, 100); //

					document.getElementById(action).value = 1;
					try
					{
						validate_submit();
					}
					catch (e)
					{
						ajax_submit_form(action);
//						document.form.submit();
					}
				}
			}
		},
		failure: function (o)
		{
			window.alert('failure - try again - once');
		},
		timeout: 5000
	});
};

ajax_submit_form = function (action)
{
	var thisForm = $('#form');
	var requestUrl = $(thisForm).attr("action");
	var formdata = false;
	if (window.FormData)
	{
		try
		{
			formdata = new FormData(thisForm[0]);
		}
		catch (e)
		{

		}
	}

	$.ajax({
		cache: false,
		contentType: false,
		processData: false,
		type: 'POST',
		url: requestUrl + '&phpgw_return_as=json',
		data: formdata ? formdata : thisForm.serialize(),
		success: function (data, textStatus, jqXHR)
		{
			if (data)
			{
				if (data.status == "saved")
				{
					var id = data.id;
					if (action == 'apply')
					{
						var oArgs = {menuaction: 'property.uitenant_claim.edit',
							id: id,
							tab: 'general'
						};
					}
					else
					{
						var oArgs = {menuaction: 'property.uitenant_claim.index'};
					}

					redirect_action = phpGWLink('index.php', oArgs);
					if (pendingList === 0)
					{
						window.location.href = redirect_action;
					}
					else
					{
						sendAllFiles(id);
					}
				}
				else
				{
					var send_buttons = $('.pure-button');
					$(send_buttons).each(function ()
					{
						$(this).prop('disabled', false);
					});
					var element = document.getElementById('spinner');
					if (element)
					{
						element.parentNode.removeChild(element);
					}

					alert(data.message);
				}
			}
		}
	});
};




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
	// Initialize file count display
	$('#files-count').text('0');
	
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

	formatFileSize = function (bytes)
	{
		if (typeof bytes !== 'number')
		{
			return '';
		}
		if (bytes >= 1000000000)
		{
			return (bytes / 1000000000).toFixed(2) + ' GB';
		}
		if (bytes >= 1000000)
		{
			return (bytes / 1000000).toFixed(2) + ' MB';
		}
		return (bytes / 1000).toFixed(2) + ' KB';
	};


	sendAllFiles = function (id)
	{

		$('#fileupload').fileupload(
			'option',
			'url',
			phpGWLink('index.php', {menuaction: 'property.uitenant_claim.handle_multi_upload_file', id: id})
			);

		$.each($('.start_file_upload'), function (index, file_start)
		{
			file_start.click();
		});
	};

	$('#fileupload').fileupload({
		dropZone: $('#drop-area'),
		uploadTemplateId: null,
		downloadTemplateId: null,
		autoUpload: false,
		add: function (e, data)
		{
			$.each(data.files, function (index, file)
			{
				var file_size = formatFileSize(file.size);

				data.context = $('<p class="file">')
					.append($('<span>').text(data.files[0].name + ' ' + file_size))
					.appendTo($(".content_upload_download"))
					.append($('<button type="button" class="start_file_upload" style="display:none">start</button>')
						.click(function ()
						{
							data.submit();
						}));

				pendingList++;
				$('#files-count').text(pendingList);

			});

		},
		progress: function (e, data)
		{
			var progress = parseInt((data.loaded / data.total) * 100, 10);
			data.context.css("background-position-x", 100 - progress + "%");
		},
		done: function (e, data)
		{
			file_count++;

			var result = JSON.parse(data.result);

			if (result.files[0].error)
			{
				data.context
					.removeClass("file")
					.addClass("error")
					.append($('<span>').text(' Error: ' + result.files[0].error));
			}
			else
			{
				data.context
					.addClass("done");
			}

			if (file_count === pendingList)
			{
				window.location.href = redirect_action;
			}

		},
		limitConcurrentUploads: 1,
		maxChunkSize: 8388000
	});

	$(document).bind('dragover', function (e)
	{
		var dropZone = $('#drop-area'),
			timeout = window.dropZoneTimeout;
		if (timeout)
		{
			clearTimeout(timeout);
		}
		else
		{
			dropZone.addClass('in');
		}
		var hoveredDropZone = $(e.target).closest(dropZone);
		dropZone.toggleClass('hover', hoveredDropZone.length);
		window.dropZoneTimeout = setTimeout(function ()
		{
			window.dropZoneTimeout = null;
			dropZone.removeClass('in hover');
		}, 100);
	});

	$(document).bind('drop dragover', function (e)
	{
		e.preventDefault();
	});
});