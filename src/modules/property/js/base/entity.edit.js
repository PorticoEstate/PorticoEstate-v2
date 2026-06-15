/* global get_files_java_url, get_checklists_url, get_cases_url, get_controls_url, get_cases_for_checklist_url, location_id, item_id, multi_upload_url */

/**
 * Read the current entity's type / entity_id / cat_id from the page.
 *
 * Sources tried in order:
 *   1. Hidden inputs #field_type and #field_entity_id (always present in entity.xsl).
 *   2. #cat_id select element (present when the category list has more than one entry).
 *   3. The form's REST-style action attribute: /property/entity/{type}/{entity_id}/{cat_id}...
 *
 * @returns {{type: string, entityId: string, catId: string}}
 */
function getEntityContext()
{
	var type = ($('#field_type').val() || '').toString();
	var entityId = ($('#field_entity_id').val() || '').toString();
	var catId = ($('#cat_id').val() || '').toString();

	if (!catId || catId === '0')
	{
		var formAction = ($('#form').attr('action') || '').toString();
		var pathMatch = formAction.match(/\/property\/entity\/([^/]+)\/(\d+)\/(\d+)/);
		if (pathMatch)
		{
			if (!type)     { type     = decodeURIComponent(pathMatch[1]); }
			if (!entityId) { entityId = pathMatch[2]; }
			catId = pathMatch[3];
		}
		else
		{
			var qIndex = formAction.indexOf('?');
			if (qIndex !== -1)
			{
				var params = new URLSearchParams(formAction.substring(qIndex + 1));
				if (!type)     { type     = params.get('type')      || ''; }
				if (!entityId) { entityId = params.get('entity_id') || ''; }
				catId = params.get('cat_id') || '';
			}
		}
	}

	return {type: type, entityId: entityId, catId: catId};
}

formatEntityFileLink = function (key, oData)
{
	var name = (oData && oData[key]) ? String(oData[key]) : '';
	var url = '';
	if (!name)
	{
		return '';
	}

	if (oData && oData.file_id)
	{
		url = phpGWLink('index.php', {
			menuaction: 'property.uientity.view_file',
			loc1: oData.loc1 || '',
			id: oData.item_id || '',
			cat_id: oData.cat_id || '',
			entity_id: oData.entity_id || '',
			type: oData.type || '',
			file_id: oData.file_id
		});
	}

	if (!url)
	{
		return $('<div/>').text(name).html();
	}

	return '<a href="' + encodeURI(url) + '" target="_blank" rel="noopener">'
		+ $('<div/>').text(name).html()
		+ '</a>';
};

formatEntityDeleteFileCheckbox = function (key, oData)
{
	var fileId = (oData && oData[key] !== undefined && oData[key] !== null) ? String(oData[key]) : '';
	if (!fileId)
	{
		return '';
	}

	return "<input type='checkbox' name='values[file_action][]' value='" + $('<div/>').text(fileId).html()
		+ "' title='" + $('<div/>').text('Check to delete file').html() + "'>";
};

formatEntityRelatedLink = function (key, oData)
{
	var text = (oData && oData[key]) ? String(oData[key]) : '';
	if (!text)
	{
		return '';
	}

	var path = (oData && oData.related_path) ? String(oData.related_path) : '';
	var params = (oData && oData.related_params && typeof oData.related_params === 'object')
		? oData.related_params
		: null;
	var url = '';

	if (path && params)
	{
		url = phpGWLink(path, params);
	}
	else if (path)
	{
		url = path;
	}

	if (!url)
	{
		return $('<div/>').text(text).html();
	}

	return '<a href="' + encodeURI(url) + '">' + $('<div/>').text(text).html() + '</a>';
};

formatEntityTargetLink = function (key, oData)
{
	var text = (oData && oData[key] !== undefined && oData[key] !== null) ? String(oData[key]) : '';
	if (!text)
	{
		return '';
	}

	var path = (oData && oData.target_path) ? String(oData.target_path) : '';
	var params = (oData && oData.target_params && typeof oData.target_params === 'object')
		? oData.target_params
		: null;
	var url = '';

	if (path && params)
	{
		url = phpGWLink(path, params);
	}
	else if (path)
	{
		url = path;
	}

	if (!url)
	{
		return $('<div/>').text(text).html();
	}

	return '<a href="' + encodeURI(url) + '">' + $('<div/>').text(text).html() + '</a>';
};

formatEntityDocumentLink = function (key, oData)
{
	var name = (oData && oData[key]) ? String(oData[key]) : '';
	if (!name)
	{
		return '';
	}

	var source = (oData && oData.document_source) ? String(oData.document_source) : '';
	var documentId = (oData && oData.document_id) ? String(oData.document_id) : '';
	var url = '';

	if (source === 'generic' && documentId)
	{
		url = phpGWLink('index.php', {
			menuaction: 'property.uigeneric_document.view_file',
			file_id: documentId
		});
	}
	else if (documentId)
	{
		url = phpGWLink('index.php', {
			menuaction: 'property.uidocument.view_file',
			id: documentId
		});
	}

	if (!url)
	{
		return $('<div/>').text(name).html();
	}

	return '<a href="' + encodeURI(url) + '" target="_blank" rel="noopener">'
		+ $('<div/>').text(name).html()
		+ '</a>';
};

this.fileuploader = function ()
{
	var sUrl = multi_upload_url || phpGWLink('index.php', multi_upload_parans);
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
	var ctx = getEntityContext();
	if (!ctx.type || !ctx.entityId || !ctx.catId || !id)
	{
		return;
	}

	var sUrl = '/property/entity/' + encodeURIComponent(ctx.type)
		+ '/' + encodeURIComponent(ctx.entityId)
		+ '/' + encodeURIComponent(ctx.catId)
		+ '/' + encodeURIComponent(id)
		+ '/inventory/add?location_id=' + encodeURIComponent(location_id);

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
	var ctx = getEntityContext();
	if (!ctx.type || !ctx.entityId || !ctx.catId || !id || !inventory_id)
	{
		return;
	}

	var sUrl = '/property/entity/' + encodeURIComponent(ctx.type)
		+ '/' + encodeURIComponent(ctx.entityId)
		+ '/' + encodeURIComponent(ctx.catId)
		+ '/' + encodeURIComponent(id)
		+ '/inventory/' + encodeURIComponent(inventory_id)
		+ '/edit?location_id=' + encodeURIComponent(location_id);

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
	var ctx = getEntityContext();
	if (!ctx.type || !ctx.entityId || !ctx.catId || !id || !inventory_id)
	{
		return;
	}

	var sUrl = '/property/entity/' + encodeURIComponent(ctx.type)
		+ '/' + encodeURIComponent(ctx.entityId)
		+ '/' + encodeURIComponent(ctx.catId)
		+ '/' + encodeURIComponent(id)
		+ '/inventory/' + encodeURIComponent(inventory_id)
		+ '/calendar?location_id=' + encodeURIComponent(location_id);

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
	var ctx = getEntityContext();
	if (!ctx.type || !ctx.entityId || !ctx.catId || !serie_id)
	{
		return;
	}

	var sUrl = '/property/entity/' + encodeURIComponent(ctx.type)
		+ '/' + encodeURIComponent(ctx.entityId)
		+ '/' + encodeURIComponent(ctx.catId)
		+ '/assigned-history?serie_id=' + encodeURIComponent(serie_id);

	TINY.box.show({iframe: sUrl, boxid: 'frameless', width:Math.round($(window).width()*0.9), height:Math.round($(window).height()*0.9), fixed: false, maskid: 'darkmask', maskopacity: 40, mask: true, animate: true,
		close: true,
		closejs: false
	});
}

this.refresh_inventory = function (location_id, id)
{
	var type = $('#field_type').val() || '';
	var entityId = $('#field_entity_id').val() || '';
	var catId = $('#cat_id').val() || '';

	if (!(type && entityId && catId && id))
	{
		return;
	}

	var requestUrl = '/property/entity/' + encodeURIComponent(type)
		+ '/' + encodeURIComponent(entityId)
		+ '/' + encodeURIComponent(catId)
		+ '/' + encodeURIComponent(id)
		+ '/inventory';

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

		var oArgs = {location_id: location_id, id: item_id };
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


		var requestUrl2 = get_controls_url + '?location_id=' + encodeURIComponent(location_id) + '&id=' + encodeURIComponent(item_id);
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
	var requestUrl = phpGWLink('property/location/component/add-control', oArgs);
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

function getFieldValue(form, selector)
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
	var explicitLocationCode = getFieldValue(form, 'input[name="location_code"]');
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

	if (locationParts.length)
	{
		locationParts.sort(function (a, b)
		{
			return a.level - b.level;
		});
		return locationParts.map(function (part)
		{
			return part.value;
		}).join('-');
	}

	return '';
}

function extractPrimaryRelationFromLocationForm(form)
{
	var relation = {
		p_num: '',
		p_entity_id: '',
		p_cat_id: ''
	};

	var relationNumInputs = form.querySelectorAll('input[name^="entity_num_"]');
	for (var i = 0; i < relationNumInputs.length; i++)
	{
		var numInput = relationNumInputs[i];
		var pNum = (numInput.value || '').trim();
		if (!pNum)
		{
			continue;
		}

		var suffix = (numInput.name || '').replace('entity_num_', '');
		relation.p_num = pNum;
		relation.p_entity_id = getFieldValue(form, 'input[name="entity_id_' + suffix + '"]');
		relation.p_cat_id = getFieldValue(form, 'input[name="cat_id_' + suffix + '"]');
		break;
	}

	return relation;
}

function buildRelationInfo(form)
{
	var relationInfo = {};
	var locationCode = buildLocationCodeFromLocationForm(form);
	if (locationCode)
	{
		relationInfo.location_code = locationCode;
	}

	var relation = extractPrimaryRelationFromLocationForm(form);
	if (relation.p_num)
	{
		relationInfo.p_num = relation.p_num;
	}
	if (relation.p_entity_id)
	{
		relationInfo.p_entity_id = relation.p_entity_id;
	}
	if (relation.p_cat_id)
	{
		relationInfo.p_cat_id = relation.p_cat_id;
	}

	var tenantId = getFieldValue(form, 'input[name="tenant_id"]');
	if (tenantId)
	{
		relationInfo.tenant_id = tenantId;
	}

	var origin = getFieldValue(form, 'input[name="values[origin]"]');
	if (origin)
	{
		relationInfo.origin = origin;
	}

	var originId = getFieldValue(form, 'input[name="values[origin_id]"]');
	if (originId)
	{
		relationInfo.origin_id = originId;
	}

	return relationInfo;
}

function appendRelationInfoToFormData(formData, form)
{
	var relationInfo = buildRelationInfo(form);
	var keys = Object.keys(relationInfo);
	for (var i = 0; i < keys.length; i++)
	{
		var key = keys[i];
		formData.set('RelationInfo[' + key + ']', relationInfo[key]);
	}
}

function logRelationInfoDebug(formData)
{
	if (typeof window === 'undefined' || !window.PORTICO_DEBUG_RELATION_INFO)
	{
		return;
	}

	var relationInfo = {};
	formData.forEach(function (value, key)
	{
		var match = key.match(/^RelationInfo\[(.+)\]$/);
		if (!match)
		{
			return;
		}
		relationInfo[match[1]] = value;
	});

	if (window.console && typeof window.console.log === 'function')
	{
		window.console.log('RelationInfo payload:', relationInfo);
	}
}

function createEntityNavigationClient(form)
{
	if (window.PorticoBoundaryClients && typeof window.PorticoBoundaryClients.createEntityClients === 'function')
	{
		return window.PorticoBoundaryClients.createEntityClients(form, {
			parseURL: parseURL
		}).navigation;
	}

	return {
		buildEditUrl: function (type, entityId, catId, id)
		{
			return phpGWLink('index.php', {
				menuaction: 'property.uientity.edit',
				type: type,
				entity_id: entityId,
				cat_id: catId,
				id: id
			});
		},
		buildIndexUrl: function (type, entityId, catId)
		{
			return phpGWLink('index.php', {
				menuaction: 'property.uientity.index',
				entity_id: entityId,
				cat_id: catId,
				type: type
			});
		}
	};
}

function createEntityApiClient(form)
{
	if (window.PorticoBoundaryClients && typeof window.PorticoBoundaryClients.createEntityClients === 'function')
	{
		return window.PorticoBoundaryClients.createEntityClients(form, {
			parseURL: parseURL
		}).api;
	}

	var parsed = parseURL(form.action);
	var query = parsed.searchObject || {};

	return {
		buildSaveRequest: function (submitterName)
		{
			var type = query.type || '';
			var entityId = query.entity_id || '';
			var catId = query.cat_id || '';
			var isApply = (submitterName === 'values[apply]');
			var clickHistory = isApply ? '' : (query.click_history || '');

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
			if (!catId || catId === '0')
			{
				catId = $('#cat_id').val() || '';
			}

			var rawId = (query.id || item_id || '').toString();
			var id = parseInt(rawId, 10);
			var bypass = query.bypass;

			if (!type || !entityId || !catId)
			{
				return null;
			}

			var isCreate = !id;
			var url = '/property/entity/' + encodeURIComponent(type) + '/' + entityId + '/' + catId;
			if (!isCreate)
			{
				url += '/' + id;
			}

			if (!isApply && !clickHistory && typeof strBaseURL !== 'undefined' && strBaseURL)
			{
				var baseQuery = parseURL(strBaseURL).searchObject || {};
				clickHistory = baseQuery.click_history || '';
			}

			var queryParts = [];
			if (typeof bypass !== 'undefined' && bypass !== null && bypass !== '')
			{
				queryParts.push('bypass=' + encodeURIComponent(bypass));
			}
			if (clickHistory)
			{
				queryParts.push('click_history=' + encodeURIComponent(clickHistory));
			}
			if (queryParts.length)
			{
				url += '?' + queryParts.join('&');
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
	};
}

function buildEntityRestRequest(form, submitterName)
{
	return createEntityApiClient(form).buildSaveRequest(submitterName);
}

function clearFormAlerts(form)
{
	PorticoClientUtils.clearFormAlerts(form, '.rest-submit-alert');
}

function renderFormErrorAlert(form, messages)
{
	PorticoClientUtils.renderFormAlert(form, messages, {
		selector: '.rest-submit-alert',
		className: 'rest-submit-alert form-error alert alert-danger',
		headingText: 'Innsending av skjemaet feilet!',
		headingTag: 'strong',
		useList: true
	});
}

function renderFormSuccessAlert(form, messages)
{
	PorticoClientUtils.renderFormAlert(form, messages, {
		selector: '.rest-submit-alert',
		className: 'rest-submit-alert text-center alert alert-success',
		role: 'alert'
	});
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
	var isSubmitting = false;

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

		var navigationClient = createEntityNavigationClient(form);
		var restRequest = buildEntityRestRequest(form, submitter ? submitter.name : '');
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
		clearFormAlerts(form);
		isSubmitting = true;
		setSubmitButtonsDisabled(true);

		var formData = new FormData(form);
		if (submitter.name)
		{
			formData.set(submitter.name, submitter.value || '1');
		}
		appendRelationInfoToFormData(formData, form);
		logRelationInfoDebug(formData);

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
					isSubmitting = false;
					setSubmitButtonsDisabled(false);
					renderFormErrorAlert(form, errors);
					form.scrollIntoView({behavior: 'smooth', block: 'start'});
					return;
				}

				renderFormSuccessAlert(form, getSuccessMessage(data, restRequest.isCreate));
				form.scrollIntoView({behavior: 'smooth', block: 'start'});

				if (restRequest.isCreate && data.id)
				{
					var redirectUrl = navigationClient.buildEditUrl(
						restRequest.type,
						restRequest.entityId,
						restRequest.catId,
						data.id
					);
					setTimeout(function ()
					{
						window.location.href = redirectUrl;
					}, 1200);
				}
				else if (submitter && submitter.name === 'values[save]')
				{
					var indexUrl = navigationClient.buildIndexUrl(
						restRequest.type,
						restRequest.entityId,
						restRequest.catId
					);
					setTimeout(function ()
					{
						window.location.href = indexUrl;
					}, 1200);
				}
				else
				{
					isSubmitting = false;
					setSubmitButtonsDisabled(false);
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
			isSubmitting = false;
			setSubmitButtonsDisabled(false);
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