/* global get_files_java_url, get_checklists_url, get_cases_url, get_controls_url, get_cases_for_checklist_url, location_id, item_id */

this.fileuploader = function ()
{
	var sUrl = phpGWLink('index.php', multi_upload_parans);
	TINY.box.show({iframe: sUrl, boxid: 'frameless', width:Math.round($(window).width()*0.9), height:Math.round($(window).height()*0.9), fixed: false, maskid: 'darkmask', maskopacity: 40, mask: true, animate: true,
		close: true,
		closejs: function ()
		{
			refresh_files()
		}
	});	
};

this.refresh_files = function ()
{
	var strURL = get_files_java_url;

	try
	{
		refresh_glider(strURL);
	}
	catch (e)
	{

	}
	JqueryPortico.updateinlineTableHelper(oTable0, strURL);
};

this.showlightbox_add_inventory = function (location_id, id)
{
	var oArgs = {menuaction: 'property.uientity.add_inventory', location_id: location_id, id: id};
	var sUrl = phpGWLink('index.php', oArgs);

	TINY.box.show({iframe: sUrl, boxid: 'frameless', width:Math.round($(window).width()*0.9), height:Math.round($(window).height()*0.9), fixed: false, maskid: 'darkmask', maskopacity: 40, mask: true, animate: true,
		close: true,
		closejs: function ()
		{
			refresh_inventory(location_id, id)
		}
	});
}

this.showlightbox_edit_inventory = function (location_id, id, inventory_id)
{
	var oArgs = {menuaction: 'property.uientity.edit_inventory', location_id: location_id, id: id, inventory_id: inventory_id};
	var sUrl = phpGWLink('index.php', oArgs);

	TINY.box.show({iframe: sUrl, boxid: 'frameless', width:Math.round($(window).width()*0.9), height:Math.round($(window).height()*0.9), fixed: false, maskid: 'darkmask', maskopacity: 40, mask: true, animate: true,
		close: true,
		closejs: function ()
		{
			refresh_inventory(location_id, id)
		}
	});
}

this.showlightbox_show_calendar = function (location_id, id, inventory_id)
{
	var oArgs = {menuaction: 'property.uientity.inventory_calendar', location_id: location_id, id: id, inventory_id: inventory_id};
	var sUrl = phpGWLink('index.php', oArgs);

	TINY.box.show({iframe: sUrl, boxid: 'frameless', width:Math.round($(window).width()*0.9), height:Math.round($(window).height()*0.9), fixed: false, maskid: 'darkmask', maskopacity: 40, mask: true, animate: true,
		close: true,
		closejs: function ()
		{
			refresh_inventory(location_id, id)
		}
	});
}

this.showlightbox_assigned_history = function (serie_id)
{
	var oArgs = {menuaction: 'property.uientity.get_assigned_history', serie_id: serie_id};
	var sUrl = phpGWLink('index.php', oArgs);

	TINY.box.show({iframe: sUrl, boxid: 'frameless', width:Math.round($(window).width()*0.9), height:Math.round($(window).height()*0.9), fixed: false, maskid: 'darkmask', maskopacity: 40, mask: true, animate: true,
		close: true,
		closejs: false
	});
}

this.refresh_inventory = function (location_id, id)
{
	var oArgs = {menuaction: 'property.uientity.get_inventory', location_id: location_id, id: id};
	var requestUrl = phpGWLink('index.php', oArgs, true);

	var api = oTable3.api();
	api.ajax.url(requestUrl).load();
}

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
		if (action === 'delete')
		{
			alert('Sletter dersom det ikke er tilknyttet historikk');
		}

		var data = {ids: ids, action: action};
		data.repeat_interval = $("#repeat_interval").val();
		data.controle_time = $("#controle_time").val();
		data.service_time = $("#service_time").val();
		data.control_responsible = $("#control_responsible").val();
		data.control_start_date = $("#control_start_date").val();
		data.repeat_type = $("#repeat_type").val();

		var oArgs = {menuaction: 'property.controller_helper.update_control_serie', location_id : location_id, id: item_id };
		var requestUrl = phpGWLink('index.php', oArgs, true);
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


		var requestUrl2 = get_controls_url + '?location_id=' + encodeURIComponent(location_id) + '&id=' + encodeURIComponent(item_id);
		JqueryPortico.updateinlineTableHelper('datatable-container_4', requestUrl2);
	}
}

function parseURL(url)
{
	var parser = document.createElement('a'),
		searchObject = {},
		queries, split, i;
	// Let the browser do the work
	parser.href = url;
	// Convert query string to object
	queries = parser.search.replace(/^\?/, '').split('&');
	for (i = 0; i < queries.length; i++)
	{
		split = queries[i].split('=');
		searchObject[split[0]] = split[1];
	}
	return {
		protocol: parser.protocol,
		host: parser.host,
		hostname: parser.hostname,
		port: parser.port,
		pathname: parser.pathname,
		search: parser.search,
		searchObject: searchObject,
		hash: parser.hash
	};
}

add_control = function ()
{
	oArgs = {location_id:location_id, id: item_id};
	oArgs.menuaction = 'property.controller_helper.add_control';
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
	var requestUrl = phpGWLink('index.php', oArgs, true);
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

	var requestUrl2 = get_controls_url + '?location_id=' + encodeURIComponent(location_id) + '&id=' + encodeURIComponent(item_id);
	JqueryPortico.updateinlineTableHelper('datatable-container_4', requestUrl2);
};


var documents = null;
var requestUrlDoc = null;
	
$(document).ready(function ()
{
	$('#doc_type').change( function()
	{
		paramsTable7['doc_type'] = $(this).val();
		oTable7.api().draw();				
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

	var click_action_on_table = false;
	$("#check_lst_time_span").change(function ()
	{
		var requestUrl = get_checklists_url + '?location_id=' + encodeURIComponent(location_id) + '&id=' + encodeURIComponent(item_id) + '&year=' + encodeURIComponent($(this).val());
		var _oTable = JqueryPortico.updateinlineTableHelper('datatable-container_5', requestUrl);

		requestUrl = get_cases_url + '?location_id=' + encodeURIComponent(location_id) + '&id=' + encodeURIComponent(item_id) + '&year=' + encodeURIComponent($(this).val());
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

function updateCaseTable(check_list_id)
{
	if (!check_list_id)
	{
		return;
	}
	var requestUrl = get_cases_for_checklist_url + '?check_list_id=' + encodeURIComponent(check_list_id);
	JqueryPortico.updateinlineTableHelper('datatable-container_6', requestUrl);
}

function newDocument(oArgs)
{
	oArgs['doc_type'] = $('#doc_type').val();
	oArgs['from'] = 'property.uientity.edit';

	var requestUrl = phpGWLink('index.php', oArgs);

	window.open(requestUrl, '_self');
};

function parseFormKeyTokens(key)
{
	var tokens = [];
	var match;
	var regex = /([^\[\]]+)/g;
	while ((match = regex.exec(key)) !== null)
	{
		tokens.push(match[1]);
	}
	return tokens;
}

function setNestedValue(target, key, value)
{
	var tokens = parseFormKeyTokens(key);
	var forceArray = /\[\]$/.test(key);
	if (!tokens.length)
	{
		return;
	}

	var node = target;
	for (var i = 0; i < tokens.length - 1; i++)
	{
		var token = tokens[i];
		if (!Object.prototype.hasOwnProperty.call(node, token) || typeof node[token] !== 'object' || node[token] === null)
		{
			node[token] = {};
		}
		node = node[token];
	}

	var leaf = tokens[tokens.length - 1];
	if (!Object.prototype.hasOwnProperty.call(node, leaf))
	{
		node[leaf] = forceArray ? [value] : value;
		return;
	}

	if (Array.isArray(node[leaf]))
	{
		node[leaf].push(value);
		return;
	}

	node[leaf] = [node[leaf], value];
}

function formDataToObject(formData)
{
	var payload = {};
	formData.forEach(function (value, key)
	{
		setNestedValue(payload, key, value);
	});
	return payload;
}

function buildEntityRestRequest(form)
{
	var parsed = parseURL(form.action);
	var query = parsed.searchObject || {};
	var type = query.type || '';
	var entityId = query.entity_id || '';
	var catId = query.cat_id || '';

	if (!type || !entityId || !catId)
	{
		var pathMatch = parsed.pathname.match(/\/property\/entity\/([^\/]+)\/(\d+)\/(\d+)/);
		if (pathMatch)
		{
			if (!type) { type = decodeURIComponent(pathMatch[1]); }
			if (!entityId) { entityId = pathMatch[2]; }
			if (!catId) { catId = pathMatch[3]; }
		}
	}

	if (!type) { type = $('#field_type').val() || ''; }

	var rawId = (query.id || item_id || '').toString();
	var id = parseInt(rawId, 10);
	var bypass = query.bypass;

	if (!type || !entityId || !catId)
	{
		return null;
	}

	var isCreate = !id;
	var url = '/property/entity/' + encodeURIComponent(type) + '/' + entityId + '/' + catId;
	if (isCreate)
	{
		url += '/create';
	}
	else
	{
		url += '/' + id;
	}

	if (typeof bypass !== 'undefined' && bypass !== null && bypass !== '')
	{
		url += '?bypass=' + encodeURIComponent(bypass);
	}

	return {
		url: url,
		method: isCreate ? 'POST' : 'PUT',
		isCreate: isCreate,
		type: type,
		entityId: entityId,
		catId: catId
	};
}

function clearFormAlerts(form)
{
	var notices = form.querySelectorAll('.rest-submit-alert');
	for (var i = 0; i < notices.length; i++)
	{
		notices[i].remove();
	}
}

function renderFormErrorAlert(form, messages)
{
	clearFormAlerts(form);

	var alert = document.createElement('div');
	alert.className = 'rest-submit-alert form-error alert alert-danger';

	var heading = document.createElement('strong');
	heading.textContent = 'Innsending av skjemaet feilet!';
	alert.appendChild(heading);

	var list = document.createElement('ul');
	for (var i = 0; i < messages.length; i++)
	{
		var item = document.createElement('li');
		item.textContent = messages[i];
		list.appendChild(item);
	}
	alert.appendChild(list);

	form.insertBefore(alert, form.firstChild);
}

function renderFormSuccessAlert(form, messages)
{
	clearFormAlerts(form);

	var alert = document.createElement('div');
	alert.className = 'rest-submit-alert text-center alert alert-success';
	alert.setAttribute('role', 'alert');

	var lines = Array.isArray(messages) ? messages : [messages];
	for (var i = 0; i < lines.length; i++)
	{
		if (i > 0)
		{
			alert.appendChild(document.createElement('br'));
		}
		alert.appendChild(document.createTextNode(lines[i]));
	}

	form.insertBefore(alert, form.firstChild);
}

function extractReceiptMessages(entries)
{
	if (!Array.isArray(entries))
	{
		return [];
	}

	return entries
		.map(function (entry)
		{
			if (entry && typeof entry.msg === 'string')
			{
				return entry.msg;
			}
			if (typeof entry === 'string')
			{
				return entry;
			}
			return '';
		})
		.filter(function (message)
		{
			return !!message;
		});
}

function getErrorMessages(data)
{
	var topLevel = extractReceiptMessages(data && data.error);
	if (topLevel.length)
	{
		return topLevel;
	}

	return extractReceiptMessages(data && data.receipt && data.receipt.error);
}

function getSuccessMessage(data, isCreate)
{
	var topLevel = extractReceiptMessages(data && data.message);
	if (topLevel.length)
	{
		return topLevel;
	}

	var receipt = extractReceiptMessages(data && data.receipt && data.receipt.message);
	if (receipt.length)
	{
		return receipt;
	}

	var id = (data && data.id) ? data.id : item_id;
	if (isCreate)
	{
		return ['Post ' + id + ' er opprettet'];
	}

	return ['Post ' + id + ' er oppdatert'];
}

function hasSelectedFileUpload(form)
{
	var fileInput = form.querySelector('input[type="file"][name="file"]');
	if (fileInput && fileInput.files && fileInput.files.length > 0)
	{
		return true;
	}

	var jasperInput = form.querySelector('input[type="file"][name="jasperfile"]');
	if (jasperInput && jasperInput.files && jasperInput.files.length > 0)
	{
		return true;
	}

	return false;
}

function getMissingReadonlyRequiredMessages(form)
{
	var messages = [];
	var requiredReadonlyInputs = form.querySelectorAll('input[data-validation*="required"].readonly, input[readonly][data-validation*="required"]');

	for (var i = 0; i < requiredReadonlyInputs.length; i++)
	{
		var input = requiredReadonlyInputs[i];
		var value = (input.value || '').trim();
		if (!value)
		{
			messages.push(input.getAttribute('data-validation-error-msg') || 'Fyll ut obligatoriske felter');
		}
	}

	return messages;
}

$(document).ready(function ()
{
	var form = document.getElementById('form');
	if (!form || !window.fetch)
	{
		return;
	}

	var clickedSubmitter = null;
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

			var test = $('form').isValid(false, conf);
			if (!test)
			{
				e.preventDefault();
				return false;
			}
		}

		var missingReadonlyMessages = getMissingReadonlyRequiredMessages(form);
		if (missingReadonlyMessages.length)
		{
			e.preventDefault();
			renderFormErrorAlert(form, missingReadonlyMessages);
			form.scrollIntoView({behavior: 'smooth', block: 'start'});
			return false;
		}

		var submitter = (e.originalEvent && e.originalEvent.submitter)
			? e.originalEvent.submitter
			: clickedSubmitter;
		if (!submitter || (submitter.name !== 'values[apply]' && submitter.name !== 'values[save]'))
		{
			return true;
		}

		var restRequest = buildEntityRestRequest(form);
		if (!restRequest)
		{
			return true;
		}

		e.preventDefault();
		clearFormAlerts(form);

		var formData = new FormData(form);
		if (submitter.name)
		{
			formData.set(submitter.name, submitter.value || '1');
		}

		var fetchOptions;
		if (hasSelectedFileUpload(form))
		{
			// Let the browser set Content-Type: multipart/form-data with boundary.
			// Slim's getParsedBody() + $_FILES will handle it on the server side.
			fetchOptions = {
				method: restRequest.method,
				credentials: 'same-origin',
				body: formData
			};
		}
		else
		{
			fetchOptions = {
				method: restRequest.method,
				headers: {'Content-Type': 'application/json'},
				credentials: 'same-origin',
				body: JSON.stringify(formDataToObject(formData))
			};
		}

		fetch(restRequest.url, fetchOptions)
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
							var error = new Error('Failed to save via REST');
							error.responseData = data;
							throw error;
						}

						return data;
					});
			})
			.then(function (data)
			{
				var errors = getErrorMessages(data);
				if (errors.length)
				{
					renderFormErrorAlert(form, errors);
					form.scrollIntoView({behavior: 'smooth', block: 'start'});
					return;
				}

				renderFormSuccessAlert(form, getSuccessMessage(data, restRequest.isCreate));
				form.scrollIntoView({behavior: 'smooth', block: 'start'});

				if (restRequest.isCreate && data.id)
				{
					var redirectUrl = '/property/entity/' + encodeURIComponent(restRequest.type)
						+ '/' + encodeURIComponent(restRequest.entityId)
						+ '/' + encodeURIComponent(restRequest.catId)
						+ '?id=' + encodeURIComponent(data.id);
					setTimeout(function ()
					{
						window.location.href = redirectUrl;
					}, 1200);
				}
				else if (submitter && submitter.name === 'values[save]')
				{
					var indexUrl = 'index.php?menuaction=property.uientity.index'
						+ '&entity_id=' + encodeURIComponent(restRequest.entityId)
						+ '&cat_id=' + encodeURIComponent(restRequest.catId)
						+ '&type=' + encodeURIComponent(restRequest.type);
					setTimeout(function ()
					{
						window.location.href = indexUrl;
					}, 1200);
				}
				else
				{
					try
					{
						refresh_files();
					}
					catch (e)
					{
						// refresh_files not available on all entity forms
					}
				}
			})
		.catch(function (error)
		{
			var errors = getErrorMessages(error && error.responseData);
			if (!errors.length)
			{
				errors = ['Feil ved lagring. Vennligst prøv igjen.'];
			}
			renderFormErrorAlert(form, errors);
			form.scrollIntoView({behavior: 'smooth', block: 'start'});
		});

		return false;
	});
});