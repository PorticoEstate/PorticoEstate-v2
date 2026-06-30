/* global project_id */
/* global order_id */

//var amount = 0;
var local_value_budget;
var accumulated_budget_amount;
var vendor_id;
var project_ecodimb;

formatWorkorderDataCell = function (mode, key, oData)
{
	var rawValue = (oData && oData[key] !== undefined && oData[key] !== null) ? String(oData[key]) : '';

	if (mode === 'link' && rawValue && rawValue.indexOf('<a') !== -1)
	{
		return rawValue;
	}

	if (mode === 'checkbox' && rawValue && rawValue.indexOf('<input') !== -1)
	{
		return rawValue;
	}

	if (mode === 'link')
	{
		if (!rawValue)
		{
			return '';
		}

		if (oData && oData.img_id !== undefined && oData.img_id !== null)
		{
			return PorticoClientUtils.escapeHtml(rawValue);
		}

		var path = (oData && oData.file_view_path) ? String(oData.file_view_path) : '';
		var params = (oData && oData.file_view_params && typeof oData.file_view_params === 'object')
			? oData.file_view_params
			: null;
		var url = PorticoClientUtils.resolveLinkUrl(path, params);

		if (!url)
		{
			return PorticoClientUtils.escapeHtml(rawValue);
		}

		return PorticoClientUtils.buildAnchorHtml(rawValue, url, {
			target: '_blank',
			title: 'click to view file'
		});
	}

	if (mode === 'checkbox')
	{
		var value = (oData && oData.attach_file_value !== undefined && oData.attach_file_value !== null)
			? String(oData.attach_file_value)
			: rawValue;
		if (!value && oData && oData.file_id !== undefined && oData.file_id !== null)
		{
			value = String(oData.file_id);
		}

		if (!value)
		{
			return '';
		}

		var checked = !!(oData && (oData.attach_file_checked === true || oData.attach_file_checked === 1 || oData.attach_file_checked === '1' || oData.attach_file_checked === 'true'));
		var inputName = (oData && oData.attach_file_name) ? String(oData.attach_file_name) : 'values[file_attach][]';
		var title = (oData && oData.attach_file_title) ? String(oData.attach_file_title) : 'Check to attach file';

		return PorticoClientUtils.buildCheckboxHtml({
			checked: checked,
			name: inputName,
			value: value,
			title: title
		});
	}

	return rawValue;
};

formatWorkorderFileLink = function (key, oData)
{
	return formatWorkorderDataCell('link', key, oData);
};

formatWorkorderAttachFile = function (key, oData)
{
	return formatWorkorderDataCell('checkbox', key, oData);
};

function calculate_order()
{
	if (!validate_form())
	{
		return;
	}
	document.getElementsByName("calculate_workorder")[0].value = 1;
	check_and_submit_valid_session(function ()
	{
		submit_workorder_via_api('calculate');
	});
}

function submit_workorder()
{
	var active_tab = $("#active_tab").val();

	if (!validate_form())
	{
		return;
	}

	if (active_tab === 'general' && Number(order_id) === 0)
	{
		$('#tab-content').responsiveTabs('enable', 1);
		$('#tab-content').responsiveTabs('activate', 1);
		$("#save_button").val(lang['save']);
		$("#save_button_bottom").val(lang['save']);
		$("#active_tab").val('budget');
	}
	else
	{
		check_and_submit_valid_session(function ()
		{
			submit_workorder_via_api('save');
		});
	}
}


function send_order()
{
	if (!validate_form())
	{
		return;
	}
	document.getElementsByName("send_workorder")[0].value = 1;
	check_and_submit_valid_session(function ()
	{
		submit_workorder_via_api('send');
	});
}

check_button_names = function ()
{
	var active_tab = $("#active_tab").val();
	if (Number(order_id) === 0)
	{
		if (active_tab === 'general')
		{
			$("#save_button").val(lang['next']);
			$("#save_button_bottom").val(lang['next']);
		}
		else
		{
			$("#save_button").val(lang['save']);
			$("#save_button_bottom").val(lang['save']);
		}
	}
};


function receive_order(workorder_id)
{
	var strURL = phpGWLink('property/workorder/' + workorder_id + '/receive-order', {
		received_amount: $("#order_received_amount").val()
	}, true);
	$.ajax({
		type: 'POST',
		dataType: 'json',
		url: strURL,
		success: function (data)
		{
			if (data != null)
			{
				var msg;
				if (data['result'] == true)
				{
					msg = 'OK';
					$("#order_received_time").html(data['time']);
					$("#current_received_amount").html($("#order_received_amount").val());
				}
				else
				{
					msg = 'Error';

				}
				window.alert(msg);
			}
		},
		failure: function (o)
		{
			window.alert('failure - try again - once');
		},
		timeout: 5000
	});
}

function check_and_submit_valid_session(onValid)
{
	var oArgs = { menuaction: 'property.bocommon.confirm_session' };
	var strURL = phpGWLink('index.php', oArgs, true);
	var validCallback = (typeof onValid === 'function') ? onValid : function ()
	{
		document.form.submit();
	};

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
					validCallback();
				}
			}
		},
		failure: function (o)
		{
			window.alert('failure - try again - once');
		},
		timeout: 5000
	});
}

function createWorkorderApiClient(form)
{
	return {
		buildSaveRequest: function (currentOrderId)
		{
			var parsedOrderId = parseInt(currentOrderId, 10);
			if (!isNaN(parsedOrderId) && parsedOrderId > 0)
			{
				return {
					url: phpGWLink('property/workorder/' + parsedOrderId, {}),
					method: 'PUT'
				};
			}

			return {
				url: phpGWLink('property/workorder/create', {}),
				method: 'POST'
			};
		}
	};
}

function isWorkorderCopyRequested(form)
{
	var copyWorkorderField = form ? form.querySelector('input[name="values[copy_workorder]"]') : null;
	return !!(copyWorkorderField && copyWorkorderField.checked);
}

function normalizeWorkorderSaveRequest(saveRequest, currentOrderId, forceCreate)
{
	var parsedOrderId = parseInt(currentOrderId, 10);
	var isCreate = !!forceCreate || isNaN(parsedOrderId) || parsedOrderId <= 0;
	var method = (saveRequest && saveRequest.method) ? String(saveRequest.method).toUpperCase() : 'POST';
	var url = (saveRequest && saveRequest.url) ? saveRequest.url : phpGWLink('property/workorder/create', {});

	if (isCreate)
	{
		method = 'POST';

		if (!url || url.indexOf('/property/workorder/create') === -1)
		{
			url = phpGWLink('property/workorder/create', {});
		}
	}

	return {
		url: url,
		method: method
	};
}

function getFirstWorkorderFormDataValue(formData, keys)
{
	for (var i = 0; i < keys.length; i++)
	{
		var value = formData.get(keys[i]);
		if (value !== null && String(value) !== '')
		{
			return String(value);
		}
	}

	return '';
}

function deriveWorkorderLocationCode(formData)
{
	var partsByLevel = {};

	formData.forEach(function (rawValue, key)
	{
		var value = (rawValue === null || rawValue === undefined) ? '' : String(rawValue).trim();
		if (!value)
		{
			return;
		}

		var match = key.match(/^values\[location\]\[loc(\d+)\]$/)
			|| key.match(/^location\[loc(\d+)\]$/)
			|| key.match(/^values\[loc(\d+)\]$/)
			|| key.match(/^loc(\d+)$/);

		if (!match)
		{
			return;
		}

		var level = parseInt(match[1], 10);
		if (!Number.isFinite(level) || level <= 0)
		{
			return;
		}

		if (!Object.prototype.hasOwnProperty.call(partsByLevel, level))
		{
			partsByLevel[level] = value;
		}
	});

	var levels = Object.keys(partsByLevel).map(function (level)
	{
		return parseInt(level, 10);
	}).filter(function (level)
	{
		return Number.isFinite(level) && level > 0;
	}).sort(function (a, b)
	{
		return a - b;
	});

	if (!levels.length)
	{
		return '';
	}

	var locationParts = [];
	for (var i = 0; i < levels.length; i++)
	{
		locationParts.push(partsByLevel[levels[i]]);
	}

	return locationParts.join('-');
}

function enrichWorkorderRelationInfo(formData)
{
	var locationCode = getFirstWorkorderFormDataValue(formData, [
		'RelationInfo[location_code]',
		'values[location_code]',
		'location_code'
	]);

	if (locationCode === '')
	{
		locationCode = deriveWorkorderLocationCode(formData);
	}

	if (locationCode !== '')
	{
		formData.set('RelationInfo[location_code]', locationCode);
	}

	var relationFields = ['tenant_id', 'p_num', 'p_entity_id', 'p_cat_id', 'origin', 'origin_id'];

	for (var i = 0; i < relationFields.length; i++)
	{
		var field = relationFields[i];
		var relationKey = 'RelationInfo[' + field + ']';
		var value = getFirstWorkorderFormDataValue(formData, [
			relationKey,
			'values[' + field + ']',
			field
		]);

		if (value !== '')
		{
			formData.set(relationKey, value);
		}
	}
}

function submit_workorder_via_api(actionType)
{
	var form = document.form;
	if (!form)
	{
		return;
	}

	var action = actionType || 'save';

	var copyWorkorderRequested = isWorkorderCopyRequested(form);
	var saveRequestRaw = createWorkorderApiClient(form).buildSaveRequest(order_id);
	var saveRequest = normalizeWorkorderSaveRequest(saveRequestRaw, order_id, copyWorkorderRequested);
	var formData = new FormData(form);
	formData.set('phpgw_return_as', 'json');
	formData.set('save', '1');
	if (copyWorkorderRequested && Number(order_id) > 0)
	{
		formData.set('copy_workorder_from', String(order_id));
	}
	enrichWorkorderRelationInfo(formData);

	if (!window.fetch)
	{
		submit_workorder_via_api_xhr(saveRequest, formData, action);
		return;
	}

	fetch(saveRequest.url, {
		method: saveRequest.method,
		credentials: 'same-origin',
		body: formData
	})
		.then(function (response)
		{
			return response.json().catch(function ()
			{
				return null;
			}).then(function (data)
			{
				if (!response.ok)
				{
					var error = new Error('Workorder save failed');
					error.responseData = data;
					throw error;
				}
				return data;
			});
		})
		.then(function (data)
		{
			handle_workorder_save_success(data, action);
		})
		.catch(function (error)
		{
			handle_workorder_save_error(error && error.responseData ? error.responseData : null);
		});
}

function submit_workorder_via_api_xhr(saveRequest, formData, action)
{
	var xhr = new XMLHttpRequest();
	xhr.open(saveRequest.method, saveRequest.url, true);
	xhr.withCredentials = true;

	xhr.onreadystatechange = function ()
	{
		if (xhr.readyState !== 4)
		{
			return;
		}

		var data = null;
		if (xhr.responseText)
		{
			try
			{
				data = JSON.parse(xhr.responseText);
			}
			catch (e)
			{
				data = null;
			}
		}

		if (xhr.status >= 200 && xhr.status < 300)
		{
			handle_workorder_save_success(data, action);
			return;
		}

		handle_workorder_save_error(data);
	};

	xhr.send(formData);
}

function clearWorkorderFormAlerts()
{
	PorticoClientUtils.clearFormAlerts(document.form, '.workorder-submit-alert');
}

function renderWorkorderFormAlert(messages, type)
{
	PorticoClientUtils.renderFormAlert(document.form, messages, {
		selector: '.workorder-submit-alert',
		className: 'workorder-submit-alert text-center alert alert-' + type,
		role: 'alert'
	});
	if (document.form)
	{
		document.form.scrollIntoView({behavior: 'smooth', block: 'start'});
	}
}

function handle_workorder_save_success(data, action)
{
	var hasErrors = !!(data && data.receipt && data.receipt.error && data.receipt.error.length);
	if (hasErrors)
	{
		var errorMessages = data.receipt.error.map(function (entry)
		{
			return (entry && entry.msg) ? entry.msg : 'Could not save workorder';
		});
		renderWorkorderFormAlert(errorMessages, 'danger');
		return;
	}

	var id = (data && data.data && data.data.id) ? data.data.id : order_id;

	if (action === 'send')
	{
		window.location.href = phpGWLink('index.php', {
			menuaction: 'property.uiwo_hour.view',
			workorder_id: id,
			from: 'index'
		});
		return;
	}

	if (action === 'calculate')
	{
		window.location.href = phpGWLink('index.php', {
			menuaction: 'property.uiwo_hour.index',
			workorder_id: id
		});
		return;
	}

	var successMsg = order_id ? 'Arbeidsordren er lagret' : 'Arbeidsordren er opprettet';
	renderWorkorderFormAlert([successMsg], 'success');
	setTimeout(function ()
	{
		window.location.href = phpGWLink('index.php', {
			menuaction: 'property.uiworkorder.edit',
			id: id,
			active_tab: $('#active_tab').val()
		});
	}, 1200);
}

function handle_workorder_save_error(responseData)
{
	var messages = [];
	if (responseData && responseData.receipt && responseData.receipt.error && responseData.receipt.error.length)
	{
		messages = responseData.receipt.error.map(function (entry)
		{
			return (entry && entry.msg) ? entry.msg : '';
		}).filter(function (m) { return !!m; });
	}
	if (!messages.length)
	{
		messages = ['Feil ved lagring. Vennligst prøv igjen.'];
	}
	renderWorkorderFormAlert(messages, 'danger');
}

this.validate_form = function ()
{
	conf = {
		modules: 'location, date, security, file',
		validateOnBlur: false,
		scrollToTopOnError: true,
		errorMessagePosition: 'top'
	};
	return $('form').isValid(false, conf);
}

function set_tab(active_tab)
{
	$("#active_tab").val(active_tab);
	check_button_names();
}

this.showlightbox_manual_invoice = function (workorder_id)
{
	var oArgs = { menuaction: 'property.uiworkorder.add_invoice', order_id: workorder_id };
	var sUrl = phpGWLink('index.php', oArgs);

	TINY.box.show({
		iframe: sUrl, boxid: 'frameless', width: Math.round($(window).width() * 0.9), height: Math.round($(window).height() * 0.9), fixed: false, maskid: 'darkmask', maskopacity: 40, mask: true, animate: true,
		close: true
		//	closejs:function(){closeJS_local()}
	});
}

this.fetch_vendor_email = function ()
{
	if (document.getElementById('vendor_id').value)
	{
		base_java_url['vendor_id'] = document.getElementById('vendor_id').value;
		base_java_url['preselect_one'] = true;
	}

	if (document.getElementById('vendor_id').value != vendor_id)
	{
		var oArgs = base_java_url;
		var strURL = phpGWLink('index.php', oArgs, true);
		JqueryPortico.updateinlineTableHelper(oTable4, strURL);
		vendor_id = document.getElementById('vendor_id').value;
	}
};

this.fetch_vendor_contract = function ()
{
	if (!document.getElementById('vendor_id').value)
	{
		return;
	}

	if ($("#vendor_id").val() != vendor_id)
	{
		var requestUrl = phpGWLink('property/workorder/lookups/vendor-contract', { vendor_id: $("#vendor_id").val() }, true);
		var htmlString = "";

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

					if (data.length > 0)
					{
						$("#vendor_contract_id").attr("data-validation", "required");
						htmlString = "<option value=''> kontrakter funnet</option>";
					}
					else
					{
						$("#vendor_contract_id").removeAttr("data-validation");
						htmlString = "<option value=''> kontrakter ikke funnet</option>";
					}

					var obj = data;

					$.each(obj, function (i)
					{
						let seleced = '';
						if (i == 0 && data.length == 2)
						{
							seleced = 'selected ="selected"';
						}
						htmlString += "<option value='" + obj[i].id + "' " + seleced + ">" + obj[i].name + "</option>";
					});

					$("#vendor_contract_id").html(htmlString);
				}
			}
		});

	}
};

window.on_vendor_updated = function ()
{
	fetch_vendor_contract();
	fetch_vendor_email();
};


JqueryPortico.FormatterActive = function (key, oData)
{
	return "<div align=\"center\">" + oData['active'] + oData['active_orig'] + "</div>";
};

var strURL = phpGWLink('property/workorder/lookups/eco-service', {}, true);
JqueryPortico.autocompleteHelper(strURL, 'service_name', 'service_id', 'service_container');

var strURL = phpGWLink('property/workorder/lookups/ecodimb', {}, true);
JqueryPortico.autocompleteHelper(strURL, 'ecodimb_name', 'ecodimb', 'ecodimb_container');


var strURL = phpGWLink('property/workorder/lookups/unspsc-code', {}, true);
JqueryPortico.autocompleteHelper(strURL, 'unspsc_code_name', 'unspsc_code', 'unspsc_code_container');


// from ajax_workorder_edit.js


$(document).ready(function ()
{

	check_button_names();

	//	$('form[name=form]').submit(function (e)
	//	{
	//		e.preventDefault();
	//
	//		if (!validate_form())
	//		{
	//			return;
	//		}
	//		check_and_submit_valid_session();
	//	});

	$("#datatable-container_2 tbody").on('click', 'tr', function ()
	{
		var voucher_id = $('td', this).eq(0).text();
		var requestUrl = phpGWLink('property/project/attachments', { voucher_id: voucher_id }, true);
		JqueryPortico.updateinlineTableHelper('datatable-container_6', requestUrl);
	});


	$("#order_cat_id").select2({
		placeholder: "Select a category",
		width: '100%'
	});

	$('#order_cat_id').on('select2:open', function (e)
	{

		$(".select2-search__field").each(function ()
		{
			if ($(this).attr("aria-controls") == 'select2-order_cat_id-results')
			{
				$(this)[0].focus();
			}
		});
	});

	$("#tags").select2({
		placeholder: "Velg en eller flere tagger, eller lag ny",
		width: '50%',
		tags: true,
		language: "no",
		createTag: function (params)
		{
			var term = (params.term || '').trim();

			if (term === '')
			{
				return null;
			}

			return {
				id: term,
				text: term,
				newTag: true // add additional parameters
			};
		}
	});

	$.formUtils.addValidator({
		name: 'budget',
		validatorFunction: function (value, $el, config, languaje, $form)
		{
			//check_for_budget is defined in xsl-template
			var v = false;
			var budget = $('#field_budget').val();
			var contract_sum = $('#field_contract_sum').val();
			if ((budget != "" || contract_sum != "") || (check_for_budget > 0))
			{
				v = true;
			}
			return v;
		},
		errorMessage: lang['please enter either a budget or contrakt sum'],
		errorMessageKey: ''
	});

	$.formUtils.addValidator({
		name: 'budget_account',
		validatorFunction: function (value, $el, config, languaje, $form)
		{
			var b_account_name = $('#b_account_name').val();
			if (b_account_name)
			{
				//				var cat_id = $("#order_cat_id").val();
				//				validate_order_category({id: cat_id});
				return true;
			}
			else
			{
				return false;
			}

		},
		errorMessage: 'Ugyldig art',
		errorMessageKey: ''
	});

	$.formUtils.addValidator({
		name: 'category',
		validatorFunction: function (value, $el, config, languaje, $form)
		{
			var cat_id = $("#order_cat_id").val();
			var validatet_category = $('#validatet_category').val();

			if (cat_id && validatet_category)
			{
				$('#select2-order_cat_id-container').addClass('valid');
				$('#select2-order_cat_id-container').removeClass('error');
				return true;
			}
			else
			{
				$('#select2-order_cat_id-container').addClass('error');
				$('#select2-order_cat_id-container').removeClass('valid');
				return false;
			}
		},
		errorMessage: 'Ugyldig kategori',
		errorMessageKey: ''
	});

	function validate_order_category(data)
	{
		if (!data.id)
		{
			return data.text;
		}

		var b_account_id = $('#b_account_id').val();

		var requestUrl = phpGWLink('property/workorder/lookups/category', {
			cat_id: data.id,
			b_account_id: b_account_id
		}, true);

		$.ajax({
			type: 'POST',
			dataType: 'json',
			url: requestUrl,
			success: function (data)
			{
				if (data !== null)
				{
					if (data.active !== 1 || data.is_node === false)
					{
						alert('Ugyldig kategori - eller feil kombinasjon av art og kategori');
						$('#select2-order_cat_id-container').addClass('error');
						$('#select2-order_cat_id-container').removeClass('valid');
						$('#validatet_category').val('');
					}
					else
					{
						$('#select2-order_cat_id-container').addClass('valid');
						$('#select2-order_cat_id-container').removeClass('error');
						$('#validatet_category').val(1);
					}
				}
			},
			complete: function ()
			{

			}
		});

		return data.text;
	}

	$("#order_cat_id").change(function ()
	{
		var cat_id = $(this).val();
		validate_order_category({ id: cat_id });
	});

	validate_change_budget_account = function ()
	{
		var cat_id = $("#order_cat_id").val();
		validate_order_category({ id: cat_id });
	}

	var strURL = phpGWLink('property/workorder/lookups/b-account', {}, true);
	JqueryPortico.autocompleteHelper(strURL, 'b_account_name', 'b_account_id', 'b_account_container', null, null, null, validate_change_budget_account);

	$("#workorder_edit").on("submit", function (e)
	{

		if ($("#lean").val() == 0)
		{
			return;
		}

		e.preventDefault();
		var thisForm = $(this);
		var submitBnt = $(thisForm).find("input[type='submit']");
		var requestUrl = $(thisForm).attr("action");
		$.ajax({
			type: 'POST',
			url: requestUrl + "&phpgw_return_as=json&" + $(thisForm).serialize(),
			success: function (data)
			{
				if (data)
				{
					if (data.sessionExpired)
					{
						alert('Sesjonen er utløpt - du må logge inn på nytt');
						return;
					}

					var obj = data;

					var submitBnt = $(thisForm).find("input[type='submit']");
					if (obj.status == "updated")
					{
						$(submitBnt).val("Lagret");
					}
					else
					{
						$(submitBnt).val("Feil ved lagring");
					}

					// Changes text on save button back to original
					window.setTimeout(function ()
					{
						$(submitBnt).val('Lagre');
						$(submitBnt).addClass("not_active");
					}, 1000);

					var ok = true;
					var htmlString = "";
					if (data['receipt'] != null)
					{
						if (data['receipt']['error'] != null)
						{
							ok = false;
							for (var i = 0; i < data['receipt']['error'].length; ++i)
							{
								htmlString += "<div class=\"text-center alert alert-danger\" role=\"alert\">";
								htmlString += data['receipt']['error'][i]['msg'];
								htmlString += '</div>';
							}

						}
						if (typeof (data['receipt']['message']) != 'undefined')
						{
							for (var i = 0; i < data['receipt']['message'].length; ++i)
							{
								htmlString += "<div class=\"text-center alert alert-success\" role=\"alert\">";
								htmlString += data['receipt']['message'][i]['msg'];
								htmlString += '</div>';
							}

						}
						$("#receipt").html(htmlString);
					}

					if (ok)
					{
						parent.closeJS_remote();
						//	parent.hide_popupBox();
					}
				}
			}
		});
	});

	$("#workorder_cancel").on("submit", function (e)
	{
		if ($("#lean").val() == 0)
		{
			return;
		}
		e.preventDefault();
		parent.closeJS_remote();
		//		parent.hide_popupBox();
	});

	var test = document.getElementById('save_button');
	if (test === null)
	{
		return;
	}

	var width = $("#submitbox").width();

	$("#submitbox").css({
		position: 'absolute',
		right: '10px',
		border: '1px solid #B5076D',
		padding: '0 10px 10px 10px',
		//	width: width + 'px',
		"background - color": '#FFF',
		display: "block"
	});

	var offset = $("#submitbox").offset();
	var topPadding = 180;

	if ($("#center_content").length === 1)
	{
		$("#center_content").scroll(function ()
		{
			if ($("#center_content").scrollTop() > offset.top)
			{
				$("#submitbox").stop().animate({
					marginTop: $("#center_content").scrollTop() - offset.top + topPadding
				}, 100);
			}
			else
			{
				$("#submitbox").stop().animate({
					marginTop: 0
				}, 100);
			}
			;
		});
	}
	else
	{
		$(window).scroll(function ()
		{
			if ($(window).scrollTop() > offset.top)
			{
				$("#submitbox").stop().animate({
					marginTop: $(window).scrollTop() - offset.top + topPadding
				}, 100);
			}
			else
			{
				$("#submitbox").stop().animate({
					marginTop: 0
				}, 100);
			}
			;
		});
	}
});


var ecodimb_selection = "";

$(window).on('load', function ()
{
	ecodimb = $('#ecodimb').val();
	ecodimb = ecodimb || project_ecodimb;

	if (ecodimb)
	{
		populateTableChkApproval();
		ecodimb_selection = ecodimb;
	}
	$("#ecodimb_name").on("autocompleteselect", function (event, ui)
	{
		var ecodimb = ui.item.value;
		if (ecodimb !== ecodimb_selection)
		{
			populateTableChkApproval(ecodimb);
		}
	});

	$("#field_contract_sum").change(function ()
	{
		populateTableChkApproval();
	});

	$("#field_budget").change(function ()
	{
		populateTableChkApproval();
	});

});

function populateTableChkApproval(ecodimb)
{
	ecodimb = ecodimb || $('#ecodimb').val();
	ecodimb = ecodimb || project_ecodimb;

	if (!ecodimb)
	{
		return;
	}

	var contract_sum = Number($('#field_contract_sum').val());
	var budget_sum = Number($('#field_budget').val());


	var total_amount = Math.max((contract_sum - Number(local_value_budget) + Number(accumulated_budget_amount)),
		(budget_sum - Number(local_value_budget) + Number(accumulated_budget_amount)),
		(Number(local_value_budget), Number(accumulated_budget_amount)));

	var order_received_amount = Math.max(contract_sum, budget_sum, Number(local_value_budget));

	$("#order_received_amount").val(order_received_amount);

	var oArgs = { menuaction: 'property.uitts.check_purchase_right', ecodimb: ecodimb, amount: total_amount, project_id: project_id, order_id: order_id };
	var requestUrl = phpGWLink('index.php', oArgs, true);
	var htmlString = "";

	$.ajax({
		type: 'POST',
		dataType: 'json',
		url: requestUrl,
		success: function (data)
		{
			if (data != null)
			{
				htmlString = "<table class='pure-table pure-table-striped' style='width:100%'>";
				htmlString += "<thead><th>" + $.number(total_amount, 0, ',', '.') + "</th><th></th><th></th></thead>";
				htmlString += "<thead><th>Be om godkjenning</th><th>Adresse</th><th>Godkjent</th></thead><tbody>";
				var obj = data;
				var required = '';

				$.each(obj, function (i)
				{
					required = '';

					htmlString += "<tr><td>";

					var left_cell = "Ikke relevant";

					if (obj[i].requested === true)
					{
						left_cell = obj[i].requested_time;
					}
					else if (obj[i].is_user !== true)
					{
						if (obj[i].approved !== true)
						{
							if (obj[i].required === true)
							{
								left_cell = "<input type=\"hidden\" name=\"values[approval][" + obj[i].id + "]\" value=\"" + obj[i].address + "\"></input>";
								required = 'checked="checked" disabled="disabled"';
							}
							else
							{
								left_cell = '';
							}
							left_cell += "<input type=\"checkbox\" name=\"values[approval][" + obj[i].id + "]\" value=\"" + obj[i].address + "\"" + required + "></input>";
						}
					}
					else if (obj[i].is_user === true)
					{
						left_cell = '(Meg selv...)';
					}
					htmlString += left_cell;
					htmlString += "</td><td valign=\"top\">";
					if (obj[i].required === true || obj[i].default === true)
					{
						htmlString += '<b>[' + obj[i].address + ']</b>';
					}
					else
					{
						htmlString += obj[i].address;
					}
					htmlString += "</td>";
					htmlString += "<td>";

					if (obj[i].approved === true)
					{
						htmlString += obj[i].approved_time;
					}
					else
					{
						if (obj[i].is_user === true || obj[i].is_substitute === true)
						{
							htmlString += "<input type=\"checkbox\" name=\"values[do_approve][" + obj[i].id + "]\" value=\"" + obj[i].id + "\"></input>";
						}
					}
					htmlString += "</td>";

					htmlString += "</tr>";
				});
				htmlString += "</tbody></table>";
				$("#approval_container").html(htmlString);
			}
		},
		error: function ()
		{
			alert('feil med oppslag til fullmakter');
		}
	});
}

window.on_location_updated = function (location_code)
{
	location_code = location_code || $("#loc1").val();

	get_location_exception(location_code);

	if ($("#delivery_address").val())
	{
		return;
	}

	var oArgs = { loc1: location_code };
	var requestUrl = phpGWLink('property/location/delivery-address', oArgs);

	$.ajax({
		type: 'POST',
		dataType: 'json',
		url: requestUrl,
		success: function (data)
		{
			if (data != null)
			{
				$("#delivery_address").val(data.delivery_address);

			}
		}
	});

};

window.get_location_exception = function (location_code)
{

	var oArgs = { location_code: location_code };
	var requestUrl = phpGWLink('property/location/location-exception', oArgs);

	$.ajax({
		type: 'POST',
		dataType: 'json',
		url: requestUrl,
		success: function (data)
		{
			$("#message").html('');

			if (data != null)
			{
				var htmlString = '';
				var exceptions = data.location_exception;
				$.each(exceptions, function (k, v)
				{
					if (v.alert_vendor == 1)
					{
						htmlString += "<div class=\"text-center alert alert-danger\" role=\"alert\">";
					}
					else
					{
						htmlString += "<div class=\"text-center alert alert-success\" role=\"alert\">";
					}
					htmlString += v.severity + ": " + v.category_text;
					if (v.location_descr)
					{
						htmlString += "<br/>" + v.location_descr;
					}
					htmlString += '</div>';

				});
				$("#message").html(htmlString);
			}
		}
	});
};

this.fileuploader = function ()
{
	var sUrl = phpGWLink('index.php', multi_upload_parans);
	TINY.box.show({
		iframe: sUrl, boxid: 'frameless', width: Math.round($(window).width() * 0.9), height: Math.round($(window).height() * 0.9), fixed: false, maskid: 'darkmask', maskopacity: 40, mask: true, animate: true,
		close: true,
		closejs: function ()
		{
			refresh_files()
		}
	});
};

this.refresh_files = function ()
{
	var strURL = phpGWLink('property/workorder/' + order_id + '/files', {
		phpgw_return_as: 'json'
	}, true);
	JqueryPortico.updateinlineTableHelper('datatable-container_1', strURL);

	strURL = phpGWLink('property/workorder/' + order_id + '/files-attachments', {
		phpgw_return_as: 'json'
	}, true);
	refresh_glider(strURL);

	JqueryPortico.updateinlineTableHelper('datatable-container_8', strURL);
};
