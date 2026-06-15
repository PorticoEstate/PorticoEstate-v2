
var link_history = null;
var set_history_data = 0;

formatLocationDocumentLink = function (key, oData)
{
	var name = (oData && oData[key]) ? String(oData[key]) : '';
	var url = (oData && oData.document_url) ? String(oData.document_url) : '';
	var source = (oData && oData.document_source) ? String(oData.document_source) : '';
	if (!name)
	{
		return '';
	}

	if (!url)
	{
		if (source === 'generic' && oData && oData.id)
		{
			url = phpGWLink('index.php', {
				menuaction: 'property.uigeneric_document.view_file',
				file_id: oData.id
			});
		}
		else if (source === 'location' && oData && oData.id)
		{
			url = phpGWLink('index.php', {
				menuaction: 'property.uidocument.view_file',
				id: oData.id
			});
		}
	}

	if (!url)
	{
		return $('<div/>').text(name).html();
	}

	return '<a href="' + encodeURI(url) + '" target="_blank" rel="noopener">'
		+ $('<div/>').text(name).html()
		+ '</a>';
};

$(document).ready(function ()
{
	$('#doc_type').change( function()
	{
		paramsTable0['doc_type'] = $(this).val();
		oTable0.api().draw();				
	});

	get_history_data = function ()
	{
		if (set_history_data === 0)
		{
			JqueryPortico.updateinlineTableHelper(oTable2, link_history);
			set_history_data = 1;
		}
	};
});

function newDocument(oArgs)
{
	oArgs['doc_type'] = $('#doc_type').val();
	oArgs['from'] = 'property.uilocation.edit';

	var requestUrl = phpGWLink('index.php', oArgs);

	window.open(requestUrl, '_self');
};



function editDocument(oArgs, parameters)
{
	oArgs['from'] = 'property.uilocation.edit';
	var api = $('#datatable-container_0').dataTable().api();
	var selected = api.rows({selected: true}).data();

	if (selected.length === 0)
	{
		alert('None selected');
		return false;
	}
	var requestUrl;

	var n = 0;
	for (var n = 0; n < selected.length; ++n)
	{
		$.each(parameters.parameter, function (i, val)
		{
			if(selected[n]['type'] == 'generic')
			{
				oArgs['menuaction'] = 'property.uigeneric_document.edit';
				oArgs['id'] = selected[n][val.source];
			}
			else
			{
				oArgs[val.name] = selected[n][val.source];
			}
			requestUrl = phpGWLink('index.php', oArgs);
			window.open(requestUrl, '_self');
		});
	}
};

this.onActionsClick = function (action)
{
	$("#controller_receipt").html("");
	if (action === 'add')
	{
		add_control();
	}

	var api = $('#datatable-container_4').dataTable().api();
	var selected = api.rows({selected: true}).data();

	var numSelected = selected.length;

	if (numSelected == 0)
	{
		alert('None selected');
		return false;
	}
	var ids = [];
	for (var n = 0; n < selected.length; ++n)
	{
		var aData = selected[n];
		ids.push(aData['serie_id']);
	}

	if (ids.length > 0)
	{
		var data = {ids: ids, action: action};
		data.repeat_interval = $("#repeat_interval").val();
		data.controle_time = $("#controle_time").val();
		data.service_time = $("#service_time").val();
		data.control_responsible = $("#control_responsible").val();
		data.control_start_date = $("#control_start_date").val();
		data.repeat_type = $("#repeat_type").val();

		var oArgs = {location_id : location_id, id: item_id };
		var requestUrl = phpGWLink('property/location/component/update-control-serie', oArgs);
		$.ajax({
			type: 'POST',
			dataType: 'json',
			url: requestUrl,
			data: data,
			success: function (data)
			{
				if (data != null)
				{
					$("#controller_receipt").html(data.status + '::' + data.msg);
					if (data.status_kode == 'ok')
					{

					}
				}
			}
		});


		var oArgs2 = {location_id : location_id, id: item_id};
		var requestUrl2 = phpGWLink('property/location/component/controls', oArgs2);
		JqueryPortico.updateinlineTableHelper('datatable-container_4', requestUrl2);
	}
}

function parseURL(url)
{
	return PorticoClientUtils.parseURL(url);
}

add_control = function ()
{
	oArgs = {location_id:location_id, id: item_id};
	var requestUrl = null;
	oArgs.control_id = $("#control_id").val();
	oArgs.control_responsible = $("#control_responsible").val();
	oArgs.control_start_date = $("#control_start_date").val();
	oArgs.repeat_type = $("#repeat_type").val();
	if (!oArgs.repeat_type)
	{
		alert('velg type serie');
		return;
	}

	oArgs.repeat_interval = $("#repeat_interval").val();
	oArgs.controle_time = $("#controle_time").val();
	oArgs.service_time = $("#service_time").val();
	requestUrl = phpGWLink('property/location/component/add-control', oArgs);
//								alert(requestUrl);

	$("#controller_receipt").html("");

	$.ajax({
		type: 'POST',
		dataType: 'json',
		url: requestUrl,
		success: function (data)
		{
			if (data != null)
			{
				$("#controller_receipt").html(data.status + '::' + data.msg);
				if (data.status_kode == 'ok')
				{
					$("#control_id").val('');
					$("#control_name").val('');
					$("#control_responsible").val('');
					$("#control_responsible_user_name").val('');
					$("#control_start_date").val('');
				}
			}
		}
	});

	var oArgs2 = {location_id: location_id, id: item_id};
	var requestUrl2 = phpGWLink('property/location/component/controls', oArgs2);
	JqueryPortico.updateinlineTableHelper('datatable-container_4', requestUrl2);
};

function updateCaseTable(check_list_id)
{
	if (!check_list_id)
	{
		return;
	}
	var oArgs = {check_list_id: check_list_id};
	var requestUrl = phpGWLink('property/location/component/cases-for-checklist', oArgs);
	JqueryPortico.updateinlineTableHelper('datatable-container_6', requestUrl);
}

$(document).ready(function ()
{

	var click_action_on_table = false;
	$("#check_lst_time_span").change(function ()
	{
		var oArgs = {location_id: location_id, id: item_id, year: $(this).val()};
		var requestUrl = phpGWLink('property/location/component/checklists', oArgs);
		var _oTable = JqueryPortico.updateinlineTableHelper('datatable-container_5', requestUrl);

		oArgs = {location_id: location_id, id: item_id, year: $(this).val()};
		requestUrl = phpGWLink('property/location/component/cases', oArgs);
		JqueryPortico.updateinlineTableHelper('datatable-container_6', requestUrl);

		if (click_action_on_table == false)
		{
			$(_oTable).on("click", function (e)
			{
				var aTrs = _oTable.api().rows().nodes();
				for (var i = 0; i < aTrs.length; i++)
				{
					if ($(aTrs[i]).hasClass('selected'))
					{
						var check_list_id = $('td', aTrs[i]).eq(0).text();
						updateCaseTable(check_list_id);
					}
				}
			});
			click_action_on_table = true
		}

	});

	$("#datatable-container_5 tr").on("click", function (e)
	{
		var check_list_id = $('td', this).eq(0).text();
		updateCaseTable(check_list_id);
	});

});

function parseFormKeyTokens(key)
{
	return PorticoClientUtils.parseFormKeyTokens(key);
}

function setNestedValue(target, key, value)
{
	PorticoClientUtils.setNestedValue(target, key, value);
}

function formDataToObject(formData)
{
	return PorticoClientUtils.formDataToObject(formData);
}

function getLocationFieldValue(form, selector)
{
	var el = form.querySelector(selector);
	if (!el)
	{
		return '';
	}
	return (el.value || '').trim();
}

function buildLocationCodeFromLocationForm(form)
{
	var explicitLocationCode = getLocationFieldValue(form, 'input[name="location_code"]');
	if (explicitLocationCode)
	{
		return explicitLocationCode;
	}

	var locationParts = [];
	var locationInputs = form.querySelectorAll('input[name]');
	for (var i = 0; i < locationInputs.length; i++)
	{
		var input = locationInputs[i];
		var name = input.getAttribute('name') || '';
		var match = name.match(/^loc(\d+)$/);
		if (!match)
		{
			continue;
		}

		var value = (input.value || '').trim();
		if (!value)
		{
			continue;
		}

		locationParts.push({
			level: parseInt(match[1], 10),
			value: value
		});
	}

	if (!locationParts.length)
	{
		return '';
	}

	locationParts.sort(function (a, b)
	{
		return a.level - b.level;
	});

	return locationParts.map(function (part)
	{
		return part.value;
	}).join('-');
}

function createLocationNavigationClient(form)
{
	if (window.PorticoBoundaryClients && typeof window.PorticoBoundaryClients.createLocationClients === 'function')
	{
		return window.PorticoBoundaryClients.createLocationClients(form, {
			parseURL: parseURL,
			getLocationFieldValue: getLocationFieldValue
		}).navigation;
	}

	var parsed = parseURL(form.action);
	var query = parsed.searchObject || {};

	return {
		buildEditUrl: function (locationCode)
		{
			var typeId = query.type_id || '';
			var lookupTenant = query.lookup_tenant || '';
			var target = 'index.php?menuaction=property.uilocation.edit&location_code=' + encodeURIComponent(locationCode);

			if (typeId)
			{
				target += '&type_id=' + encodeURIComponent(typeId);
			}
			if (lookupTenant)
			{
				target += '&lookup_tenant=' + encodeURIComponent(lookupTenant);
			}

			return target;
		}
	};
}

function createLocationApiClient(form)
{
	if (window.PorticoBoundaryClients && typeof window.PorticoBoundaryClients.createLocationClients === 'function')
	{
		return window.PorticoBoundaryClients.createLocationClients(form, {
			parseURL: parseURL,
			getLocationFieldValue: getLocationFieldValue
		}).api;
	}

	var parsed = parseURL(form.action);
	var query = parsed.searchObject || {};

	return {
		buildSaveRequest: function ()
		{
			var clickHistory = query.click_history || '';
			var queryParts = [];
			var originalLocationCode = (query.location_code || getLocationFieldValue(form, 'input[name="location_code"]') || '').trim();
			var rawLocationId = '';

			if (typeof location_id !== 'undefined' && location_id !== null)
			{
				rawLocationId = String(location_id);
			}

			var routeLocationId = parseInt(rawLocationId, 10);
			var hasExistingLocation = (!isNaN(routeLocationId) && routeLocationId > 0) || !!originalLocationCode;
			var isUpdate = hasExistingLocation && !!originalLocationCode;
			var requestUrl = isUpdate
				? '/property/location/' + encodeURIComponent(originalLocationCode)
				: '/property/location';

			if (clickHistory)
			{
				queryParts.push('click_history=' + encodeURIComponent(clickHistory));
			}

			if (queryParts.length)
			{
				requestUrl += '?' + queryParts.join('&');
			}

			return {
				url: requestUrl,
				method: isUpdate ? 'PUT' : 'POST'
			};
		}
	};
}

function buildLocationRestRequest(form)
{
	return createLocationApiClient(form).buildSaveRequest();
}

function clearLocationFormAlerts(form)
{
	PorticoClientUtils.clearFormAlerts(form, '.rest-submit-alert');
}

function renderLocationFormErrorAlert(form, messages)
{
	PorticoClientUtils.renderFormAlert(form, messages, {
		selector: '.rest-submit-alert',
		className: 'rest-submit-alert form-error alert alert-danger',
		headingText: 'Saving location failed',
		headingTag: 'strong',
		useList: true
	});
}

function renderLocationFormSuccessAlert(form, message)
{
	PorticoClientUtils.renderFormAlert(form, message, {
		selector: '.rest-submit-alert',
		className: 'rest-submit-alert text-center alert alert-success',
		role: 'alert'
	});
}

function toErrorMessageArray(data)
{
	if (!data)
	{
		return ['Failed to save location. Please try again.'];
	}

	if (Array.isArray(data.errors) && data.errors.length)
	{
		return data.errors.map(function (entry)
		{
			if (typeof entry === 'string')
			{
				return entry;
			}
			if (entry && typeof entry.msg === 'string')
			{
				return entry.msg;
			}
			return '';
		}).filter(function (msg)
		{
			return !!msg;
		});
	}

	if (typeof data.message === 'string' && data.message)
	{
		return [data.message];
	}

	return ['Failed to save location. Please try again.'];
}

function buildLocationEditRedirectUrl(locationCode, form)
{
	return createLocationNavigationClient(form).buildEditUrl(locationCode);
}

$(document).ready(function ()
{
	var form = document.getElementById('form');
	if (!form || !window.fetch)
	{
		return;
	}

	var isSubmitting = false;
	var clickedSubmitter = null;

	function findSaveSubmitter()
	{
		return form.querySelector('input[type="submit"][name="save"], button[type="submit"][name="save"]');
	}

	function shouldHandleRestSubmit(submitter)
	{
		if (submitter && submitter.name === 'save')
		{
			return true;
		}

		// Browser Enter-key submits can omit submitter; default to REST save when save button exists.
		return !submitter && !!findSaveSubmitter();
	}

	function setSubmitButtonsDisabled(disabled)
	{
		var buttons = form.querySelectorAll('input[type="submit"], button[type="submit"]');
		for (var i = 0; i < buttons.length; i++)
		{
			buttons[i].disabled = disabled;
		}
	}

	$(form).on('click', 'input[type="submit"], button[type="submit"]', function ()
	{
		clickedSubmitter = this;
	});

	$(form).on('submit', function (e)
	{
		if (typeof $.fn.isValid === 'function')
		{
			var conf = $.extend({}, form.validationConfig || {}, {
				modules: (form.validationConfig && form.validationConfig.modules) || 'location, date, security, file',
				validateOnBlur: false,
				scrollToTopOnError: true,
				errorMessagePosition: 'top',
				validateHiddenInputs: true
			});

			var valid = $('form').isValid(false, conf);
			if (!valid)
			{
				e.preventDefault();
				return false;
			}
		}

		var submitter = (e.originalEvent && e.originalEvent.submitter)
			? e.originalEvent.submitter
			: clickedSubmitter;

		if (!shouldHandleRestSubmit(submitter))
		{
			return true;
		}

		var restRequest = buildLocationRestRequest(form);
		if (!restRequest)
		{
			return true;
		}

		if (isSubmitting)
		{
			e.preventDefault();
			return false;
		}

		e.preventDefault();
		clearLocationFormAlerts(form);
		isSubmitting = true;
		setSubmitButtonsDisabled(true);

		var formData = new FormData(form);
		if (submitter && submitter.name)
		{
			formData.set(submitter.name, submitter.value || '1');
		}
		else
		{
			formData.set('save', '1');
		}

		var dynamicLocationCode = buildLocationCodeFromLocationForm(form);
		if (dynamicLocationCode)
		{
			formData.set('location_code', dynamicLocationCode);
		}

		fetch(restRequest.url, {
			method: restRequest.method,
			headers: {'Content-Type': 'application/json'},
			credentials: 'same-origin',
			body: JSON.stringify(formDataToObject(formData))
		})
			.then(function (response)
			{
				return response.json()
					.catch(function ()
					{
						return null;
					})
					.then(function (data)
					{
						if (!response.ok)
						{
							var error = new Error('REST save failed');
							error.responseData = data;
							throw error;
						}

						return data;
					});
			})
			.then(function (data)
			{
				if (!data || data.status === 'error')
				{
					isSubmitting = false;
					setSubmitButtonsDisabled(false);
					renderLocationFormErrorAlert(form, toErrorMessageArray(data));
					form.scrollIntoView({behavior: 'smooth', block: 'start'});
					return;
				}

				var savedLocationCode = data.location_code || (data.receipt && data.receipt.location_code) || '';
				if (!savedLocationCode)
				{
					isSubmitting = false;
					setSubmitButtonsDisabled(false);
					renderLocationFormErrorAlert(form, ['Save succeeded, but no location code was returned']);
					return;
				}

				renderLocationFormSuccessAlert(form, 'Location saved successfully');
				form.scrollIntoView({behavior: 'smooth', block: 'start'});

				var redirectUrl = buildLocationEditRedirectUrl(savedLocationCode, form);
				setTimeout(function ()
				{
					window.location.href = redirectUrl;
				}, 700);
			})
			.catch(function (error)
			{
				isSubmitting = false;
				setSubmitButtonsDisabled(false);
				renderLocationFormErrorAlert(form, toErrorMessageArray(error && error.responseData));
				form.scrollIntoView({behavior: 'smooth', block: 'start'});
			});

		return false;
	});
});
