var location_code_selection = "";
var charNumberLeftOutput = 804;
var smsSending = false;

this.get_sms_recipients = function (location_code)
{

	var oArgs = {menuaction: 'property.uiexternal_communication.get_sms_recipients', location_code: location_code};
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

				var obj = data;

				$.each(obj, function (i)
				{
					$('#sms_recipients').append($('<option/>', {
						value: obj[i].contact_phone,
						text: obj[i].name + " [" + obj[i].location_code + " - " + obj[i].address + ", " + obj[i].floor + "]::" + obj[i].contact_phone
					}));

				});

			}
		}
	}).done(function ()
	{
		$('#sms_recipients').porticoMultiselect('refresh').select2('open');
	});
};


JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'property.bolocation.get_locations'}, true),
	'location_name', 'location_code', 'location_container');


$(window).on('load', function ()
{

	$("#location_name").on("autocompleteselect", function (event, ui)
	{
		var location_code = ui.item.value;

		if (location_code !== location_code_selection)
		{
			location_code_selection = location_code;
		}
		get_sms_recipients(location_code);
	});

	$("#send_sms_to_all").on('change', function ()
	{
		if($(this).is(":checked"))
		{
			$("#location_selector").hide();
			get_sms_recipients($(this).val());
		}
		else
		{
			$("#location_selector").show();
		}

	});

	$("#sms_recipient_file").on('change', function ()
	{
		var file = this.files[0];
		if (!file)
		{
			return;
		}
		var input = this;
		var formData = new FormData();
		formData.append('file', file);
		var $status = $('#sms_import_status').text(lang['Importing recipients']);
		var $send = $('#form input[name="send"]').prop('disabled', true);
		input.disabled = true;
		$.ajax({
			url: phpGWLink('index.php', {menuaction: 'property.uiexternal_communication.import_sms_recipients'}, true),
			type: 'POST',
			data: formData,
			dataType: 'json',
			contentType: false,
			processData: false
		}).done(function (result)
		{
			if (!result || result.error || !Array.isArray(result.recipients) || !Array.isArray(result.invalid_rows))
			{
				$status.text(lang['Import failed'] + ': ' + (result && result.error ? result.error : lang['No recipients were imported.']));
				return;
			}
			var select = document.getElementById('sms_recipients');
			var existing = new Map();
			Array.prototype.forEach.call(select.options, function (option)
			{
				existing.set(option.value, option);
			});
			var fragment = document.createDocumentFragment();
			result.recipients.forEach(function (recipient)
			{
				var option = existing.get(recipient.phone);
				if (!option)
				{
					option = new Option(recipient.label + '::' + recipient.phone, recipient.phone, false, true);
					existing.set(recipient.phone, option);
					fragment.appendChild(option);
				}
				option.selected = true;
			});
			select.appendChild(fragment);
			$(select).porticoMultiselect('refresh');
			$status.text(lang['Recipients imported'] + ': ' + result.recipients.length
				+ (result.invalid_rows.length ? '. ' + lang['Invalid phone numbers in rows'] + ' (' + result.invalid_rows.length + '): ' + result.invalid_rows.join(', ') : ''));
		}).fail(function ()
		{
			$status.text(lang['Import failed']);
		}).always(function ()
		{
			input.value = '';
			input.disabled = false;
			$send.prop('disabled', false);
		});
	});

	$(document).on('submit', '#form', function (event)
	{
		if (event.isDefaultPrevented())
		{
			return;
		}
		event.preventDefault();
		if (smsSending)
		{
			return;
		}
		var recipients = $('#sms_recipients').val() || [];
		var extra = $('input[name="extra_sms_recipients"]').val() || '';
		recipients = recipients.concat(extra.split(',')).map(function (recipient)
		{
			return recipient.trim().replace(/ /g, '').replace(/-/g, '/').split('/')[0].substring(0, 8);
		}).filter(function (recipient)
		{
			return recipient !== '';
		});
		recipients = Array.from(new Set(recipients));
		if (!recipients.length)
		{
			return;
		}

		var content = $('#sms_content').val();
		var $status = $('#sms_send_status');
		var $controls = $('#form :input:enabled').prop('disabled', true);
		var failed = [];
		var offset = 0;
		smsSending = true;

		function sendNext()
		{
			if (offset >= recipients.length)
			{
				smsSending = false;
				$controls.prop('disabled', false);
				$status.text(lang['SMS sending completed'] + ': ' + (recipients.length - failed.length) + '/' + recipients.length
					+ (failed.length ? '. ' + lang['Recipients without confirmed sending'] + ': ' + failed.join(', ') : ''));
				return;
			}
			$status.text(lang['Sending SMS'] + ': ' + offset + '/' + recipients.length + '. ' + lang['Keep this page open while SMS messages are being sent.']);
			var batch = recipients.slice(offset, offset + 10);
			$.ajax({
				url: phpGWLink('index.php', {menuaction: 'property.uiexternal_communication.send_sms_batch'}, true),
				type: 'POST',
				dataType: 'json',
				data: {recipients: batch, sms_content: content}
			}).done(function (result)
			{
				if (!result || result.error || !Array.isArray(result.failed))
				{
					$status.text(lang['Sending interrupted. Check the SMS log before sending again. The current batch may have been partially sent.']
						+ ' ' + offset + '/' + recipients.length + ': ' + batch.join(', ') + (result && result.error ? ' (' + result.error + ')' : '')
						+ (failed.length ? '. ' + lang['Recipients without confirmed sending'] + ': ' + failed.join(', ') : ''));
					smsSending = false;
					return;
				}
				failed = failed.concat(result.failed);
				offset += batch.length;
				sendNext();
			}).fail(function ()
			{
				$status.text(lang['Sending interrupted. Check the SMS log before sending again. The current batch may have been partially sent.']
					+ ' ' + offset + '/' + recipients.length + ': ' + batch.join(', ')
					+ (failed.length ? '. ' + lang['Recipients without confirmed sending'] + ': ' + failed.join(', ') : ''));
				smsSending = false;
			});
		}
		sendNext();
	});

});

$(window).on('beforeunload', function (event)
{
	if (smsSending)
	{
		event.preventDefault();
		event.originalEvent.returnValue = '';
	}
});
$(function ()
{
	init_multiselect();

});

init_multiselect = function ()
{
	$("#sms_recipients").porticoMultiselect();
	var selection = $("#sms_recipients").data('select2').selection;
	var update = selection.update;
	selection.update = function (data)
	{
		if (data.length <= 100)
		{
			update.call(this, data);
			return;
		}
		this.clear();
		this.$selection.find('.select2-selection__rendered')
			.append($('<li class="select2-selection__choice"></li>').text(data.length + ' ' + lang['selected']))
			.append(this.$searchContainer);
		this.resizeSearch();
	};
};

function SmsCountKeyUp(maxChar)
{
	var msg = document.getElementById("sms_content");
	var smsLenLeft = maxChar - msg.value.length;
	if (smsLenLeft >= 0)
	{
		charNumberLeftOutput = smsLenLeft;
	}
	else
	{
		var msgMaxLen = maxChar;
		charNumberLeftOutput = 0;
		msg.value = msg.value.substring(0, msgMaxLen);
	}
}

function SmsCountKeyDown(maxChar)
{
	var msg = document.getElementById("sms_content");
	var smsLenLeft = maxChar - msg.value.length;
	if (smsLenLeft >= 0)
	{
		charNumberLeftOutput = smsLenLeft;
	}
	else
	{
		var msgMaxLen = maxChar;
		charNumberLeftOutput = 0;
		msg.value = msg.value.substring(0, msgMaxLen);
	}
}

$.formUtils.addValidator({
	name: 'sms_recipients',
	validatorFunction: function (value, $el, config, language, $form)
	{
		var v = false;
		var sms_recipients = $('#sms_recipients option:selected');
		if (sms_recipients.length > 0)
		{
			v = true;
			$(".multiselect").removeClass('btn-danger');
		}
		else
		{
			$(".multiselect").addClass('btn-danger');
		}
		return v;
	},
	errorMessage: 'select at least one recipient',
	errorMessageKey: 'select_at_least_one_recipient'
});
