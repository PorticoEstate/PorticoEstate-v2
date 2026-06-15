var project_id;
var external_project_budget_account_category = null;


formatLink = function (key, oData)
{
	var url = phpGWLink('index.php', {
		menuaction: 'property.uiworkorder.edit',
		id: oData[key]
	});
	return "<a href=" + url + ">" + oData[key] + "</a>";
};

formatLink_voucher = function (key, oData)
{
	var voucher_out_id = oData['voucher_out_id'];
	if (voucher_out_id)
	{
		var voucher_id = voucher_out_id;
	}
	else
	{
		var voucher_id = Math.abs(oData[key]);
	}

	if (oData[key] > 0)
	{
		var url = phpGWLink('index.php', {
			menuaction: 'property.uiinvoice.index',
			query: oData[key],
			voucher_id: oData[key],
			user_lid: 'all'
		});
		return "<a href=" + url + ">" + voucher_id + "</a>";
	}
	else
	{
		var paidUrl = phpGWLink('index.php', {
			menuaction: 'property.uiinvoice.index',
			voucher_id: Math.abs(oData[key]),
			user_lid: 'all',
			paid: true
		});
		return "<a href=" + paidUrl + ">" + voucher_id + "</a>";
	}
};

//var oArgs_invoicehandler_2 = {menuaction:'property.uiinvoice2.index'};
formatLink_invoicehandler_2 = function (key, oData)
{
	var voucher_out_id = oData['voucher_out_id'];
	if (voucher_out_id)
	{
		var voucher_id = voucher_out_id;
	}
	else
	{
		var voucher_id = Math.abs(oData[key]);
	}

	if (oData[key] > 0)
	{
		var url = phpGWLink('index.php', {
			menuaction: 'property.uiinvoice2.index',
			voucher_id: oData[key]
		});
		return "<a href=" + url + ">" + voucher_id + "</a>";
	}
	else
	{
		var paidUrl = phpGWLink('index.php', {
			menuaction: 'property.uiinvoice.index',
			voucher_id: Math.abs(oData[key]),
			user_lid: 'all',
			paid: true
		});
		return "<a href=" + paidUrl + ">" + voucher_id + "</a>";
	}
};

var project_link = function (key, oData)
{
	if (oData[key] > 0)
	{
		return "<a href=" + buildProjectEditUrl(oData[key]) + ">" + oData[key] + "</a>";
	}
};

var project_file_link = function (key, oData)
{
	var fileName = (oData && oData[key]) ? String(oData[key]) : '';
	var fileId = (oData && oData.file_id) ? String(oData.file_id) : '';

	if (!fileName)
	{
		return '';
	}

	if (!fileId)
	{
		return $('<div/>').text(fileName).html();
	}

	var url = phpGWLink('property/project/files/view', {file_id: fileId});
	return '<a href="' + encodeURI(url) + '" target="_blank" rel="noopener" title="' + $('<div/>').text(lang['click to view file'] || 'click to view file').html() + '">' + $('<div/>').text(fileName).html() + '</a>';
};

var project_attachment_link = function (key, oData)
{
	var fileName = (oData && oData[key]) ? String(oData[key]) : '';
	var voucherId = (oData && oData.voucher_id) ? String(oData.voucher_id) : '';

	if (!fileName)
	{
		return '';
	}

	if (!voucherId)
	{
		return $('<div/>').text(fileName).html();
	}

	var url = phpGWLink('index.php', {
		menuaction: 'property.uitts.show_attachment',
		file_name: fileName,
		key: voucherId
	});

	return '<a href="' + encodeURI(url) + '" target="_blank" rel="noopener">' + $('<div/>').text(fileName).html() + '</a>';
};

var project_file_link = function (key, oData)
{
	var fileName = (oData && oData[key]) ? String(oData[key]) : '';
	var fileId = (oData && oData.file_id) ? String(oData.file_id) : '';

	if (!fileName)
	{
		return '';
	}

	if (!fileId)
	{
		return $('<div/>').text(fileName).html();
	}

	var url = phpGWLink('property/project/files/view', {file_id: fileId});
	return '<a href="' + encodeURI(url) + '" target="_blank" rel="noopener" title="' + $('<div/>').text(lang['click to view file'] || 'click to view file').html() + '">' + $('<div/>').text(fileName).html() + '</a>';
};

var project_attachment_link = function (key, oData)
{
	var fileName = (oData && oData[key]) ? String(oData[key]) : '';
	var voucherId = (oData && oData.voucher_id) ? String(oData.voucher_id) : '';

	if (!fileName)
	{
		return '';
	}

	if (!voucherId)
	{
		return $('<div/>').text(fileName).html();
	}

	var url = phpGWLink('index.php', {
		menuaction: 'property.uitts.show_attachment',
		file_name: fileName,
		key: voucherId
	});

	return '<a href="' + encodeURI(url) + '" target="_blank" rel="noopener">' + $('<div/>').text(fileName).html() + '</a>';
};

//this.local_DrawCallback_1 = function (container)
//{
//	var api = $("#" + container).dataTable().api();
//	// Remove the formatting to get integer data for summation
//	var intVal = function (i)
//	{
//		return typeof i === 'string' ?
//			i.replace(/[\$,]/g, '') * 1 :
//			typeof i === 'number' ?
//			i : 0;
//	};
//
//	var columns = ["4", "5", "7", "8", "9"];
//
//	columns.forEach(function (col)
//	{
//		data = api.column(col, {page: 'current'}).data();
//		pageTotal = data.length ?
//			data.reduce(function (a, b)
//			{
//				return intVal(a) + intVal(b);
//			}) : 0;
//
//		pageTotal = $.number(pageTotal, 0, ',', '.');
//		$(api.column(col).footer()).html(pageTotal);
//	});
//};

this.local_DrawCallback2 = function (container)
{
	var api = $("#" + container).dataTable().api();
	// Remove the formatting to get integer data for summation
	var intVal = function (i)
	{
		return typeof i === 'string' ?
			i.replace(/[\$,]/g, '') * 1 :
			typeof i === 'number' ?
			i : 0;
	};

	var columns = ["4", "5", "6"];

	columns.forEach(function (col)
	{
		data = api.column(col, {page: 'current'}).data();
		pageTotal = data.length ?
			data.reduce(function (a, b)
			{
				return intVal(a) + intVal(b);
			}) : 0;

		pageTotal = $.number(pageTotal, 2, ',', '.');
		$(api.column(col).footer()).html(pageTotal);
	});
};

$(document).ready(function ()
{
	check_button_names();
	load_google_map();

	$.formUtils.addValidator({
		name: 'category',
		validatorFunction: function (value, $el, config, languaje, $form)
		{
			var global_category_id = $('#global_category_id').val();
			if (global_category_id)
			{
				$('#select2-global_category_id-container').addClass('valid');
				$('#select2-global_category_id-container').removeClass('error');
				return true;
			}
			else
			{
				$('#select2-global_category_id-container').addClass('error');
				$('#select2-global_category_id-container').removeClass('valid');
				return false;
			}
		},
		errorMessage: 'Ugyldig kategori',
		errorMessageKey: ''
	});


	$("#user_id").select2({
		placeholder: lang["select user"],
		language: "no",
		width: '75%'
	});
	$('#user_id').on('select2:open', function (e)
	{

		$(".select2-search__field").each(function ()
		{
			if ($(this).attr("aria-controls") == 'select2-user_id-results')
			{
				$(this)[0].focus();
			}
		});
	});

	$("#global_category_id").select2({
		placeholder: lang["select category"],
		language: "no",
		width: '75%'
	});

	$('#global_category_id').on('select2:open', function (e)
	{

		$(".select2-search__field").each(function ()
		{
			if ($(this).attr("aria-controls") == 'select2-global_category_id-results')
			{
				$(this)[0].focus();
			}
		});
	});


	$("#branch_id").select2({
		placeholder: lang['Select branch'],
		language: "no",
		width: '75%'
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
			}
		}
	});


	// $("#global_category_id").change(function ()
	// {
	// 	var oArgs = {menuaction: 'property.boworkorder.get_category', cat_id: $(this).val()};
	// 	var requestUrl = phpGWLink('index.php', oArgs, true);

	// 	var htmlString = "";

	// 	$.ajax({
	// 		type: 'POST',
	// 		dataType: 'json',
	// 		url: requestUrl,
	// 		success: function (data)
	// 		{
	// 			if (data != null)
	// 			{
	// 				if (data.active != 1)
	// 				{
	// 					alert('Denne kan ikke velges');
	// 				}
	// 			}
	// 		}
	// 	});
	// });

	$("#order_time_span").change(function ()
	{
		var requestUrl1 = phpGWLink('property/project/' + project_id + '/orders', {
			project_id: project_id,
			year: $(this).val(),
			results: -1,
			phpgw_return_as: 'json'
		}, true);
		JqueryPortico.updateinlineTableHelper(oTable1, requestUrl1);

		var requestUrl2 = phpGWLink('property/project/' + project_id + '/vouchers', {
			project_id: project_id,
			year: $(this).val(),
			phpgw_return_as: 'json'
		}, true);
		JqueryPortico.updateinlineTableHelper(oTable2, requestUrl2);
	});

//	if (typeof (oTable1) !== 'undefined')
//	{
//		var api1 = oTable1.api();
//		api1.on('draw', sum_columns_table_orders);
//	}

//	if (typeof (oTable2) !== 'undefined')
//	{
//		var api2 = oTable2.api();
//		api2.on('draw', sum_columns_table_invoice);
//	}


// -- buttons--//

	$("#submitbox").css({
		position: 'absolute',
		right: '10px',
		border: '1px solid #B5076D',
		padding: '0 10px 10px 10px',
//		width: $("#submitbox").width() + 'px',
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

	$("#datatable-container_2 tbody").on('click', 'tr', function ()
	{
		var voucher_id = $('td', this).eq(1).text();
		var requestUrl = phpGWLink('property/project/attachments', {
			voucher_id: voucher_id,
			phpgw_return_as: 'json'
		}, true);
		JqueryPortico.updateinlineTableHelper('datatable-container_8', requestUrl);
	});


	function validate_order_category(data)
	{
		if (!data.id)
		{
			return data.text;
		}

		var b_account_id = $('#b_account_id').val();
		var external_project_id = $('#external_project_id').val();

		var requestUrl = phpGWLink('property/project/lookups/category', {
			cat_id: data.id,
			b_account_id: b_account_id,
			phpgw_return_as: 'json'
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
						$('#select2-global_category_id-container').addClass('error');
						$('#select2-global_category_id-container').removeClass('valid');
						$('#validatet_category').val('');
					}
					else
					{
						if(data.mandatory_external_project == 1 && !external_project_id)
						{
							$('#external_project_name').addClass('error');
							$("#external_project_name").attr("data-validation", "required");
						}
						else
						{
							$('#external_project_name').removeClass('error');
							$('#select2-global_category_id-container').addClass('valid');
							$("#external_project_name").removeAttr("data-validation");
						}

						$('#select2-global_category_id-container').addClass('valid');
						$('#select2-global_category_id-container').removeClass('error');
						$('#validatet_category').val(1);
					}
				}
			},
			complete:function ()
			{

			}
		});

		return data.text;
	}

	$("#global_category_id").change(function ()
	{
		var cat_id = $(this).val();
		validate_order_category({id: cat_id});
	});

	validate_change_budget_account = function()
	{
		var cat_id = $("#global_category_id").val();
		validate_order_category({id: cat_id});
	}

	strURL = phpGWLink('property/project/lookups/b-account', {
		phpgw_return_as: 'json'
	}, true);
	JqueryPortico.autocompleteHelper(strURL, 'b_account_name', 'b_account_id', 'b_account_container', null, null, null, validate_change_budget_account);

	var strURL = phpGWLink('property/project/lookups/external-project', {
		phpgw_return_as: 'json'
	}, true);
	JqueryPortico.autocompleteHelper(strURL, 'external_project_name', 'external_project_id', 'external_project_container', null, null, null, validate_dim_b);
	
	strURL = phpGWLink('property/project/lookups/ecodimb', {
		phpgw_return_as: 'json'
	}, true);
	JqueryPortico.autocompleteHelper(strURL, 'ecodimb_name', 'ecodimb', 'ecodimb_container');
	
	strURL = phpGWLink('property/project/lookups/b-account', {
		role: 'group',
		phpgw_return_as: 'json'
	}, true);
	JqueryPortico.autocompleteHelper(strURL, 'b_account_group_name', 'b_account_group', 'b_account_group_container');
		
	$("#b_account_name").on("autocompleteselect", function (event, ui)
	{
		var b_account_id = ui.item.value;
		check_valid_dim_b(b_account_id);
	});

	function validate_dim_b()
	{
		var b_account_id = $("#b_account_id").val();
		check_valid_dim_b(b_account_id);
	}
	
	function check_valid_dim_b(b_account_id)
	{
		if(!b_account_id)
		{
			return;
		}
		var strURL = phpGWLink('property/project/lookups/b-account', {
			query: b_account_id,
			phpgw_return_as: 'json'
		}, true);

		$.getJSON(strURL, function (Result)
		{
			if (Result.ResultSet.Result.length > 0)
			{
				var b_account = Result.ResultSet.Result[0];
				var ecodimb_id = b_account.ecodimb

				if(external_project_budget_account_category)
				{
					var valid_category = false;
					var external_project_budget_account_category_arr = external_project_budget_account_category.split(',');
					for (a in external_project_budget_account_category_arr )
					{
						if(external_project_budget_account_category_arr[a] == b_account.category)
						{
							valid_category = true;
						}
					}
					if(!valid_category)
					{
						alert('Arten er ikke gyldig for dette eksterne prosjektet');
						$('#b_account_name').val('');
						$('#b_account_id').val('');
						return;
					}
				}

				if (ecodimb_id && ecodimb_id !== $('#ecodimb').val())
				{

					var strURL = phpGWLink('property/project/lookups/ecodimb', {
						query: ecodimb_id,
						phpgw_return_as: 'json'
					}, true);
					$.getJSON(strURL, function (Result)
					{
						if (Result.ResultSet.Result.length > 0)
						{
							var ecodimb = Result.ResultSet.Result[0];
							alert('Skifter ut ansvarssted "' + $('#ecodimb_name').val() + '" med "' + ecodimb.name + '"');

							$('#ecodimb').val(ecodimb.id);
							$('#ecodimb_name').val(ecodimb.name);
						}
					});

				}
			}
		});
		
	}

});


function addSubEntry()
{
	document.add_sub_entry_form.submit();
}

function parseProjectURL(url)
{
	var parser = document.createElement('a');
	var searchObject = {};
	var queries;
	var split;
	var i;

	parser.href = url;
	queries = parser.search.replace(/^\?/, '').split('&');
	for (i = 0; i < queries.length; i++)
	{
		if (!queries[i])
		{
			continue;
		}
		split = queries[i].split('=');
		var queryKey = split[0] ? decodeURIComponent(split[0]) : '';
		if (!queryKey)
		{
			continue;
		}

		var queryValue = split.length > 1 ? split.slice(1).join('=') : '';
		searchObject[queryKey] = decodeURIComponent((queryValue || '').replace(/\+/g, ' '));
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

function getProjectNavigationContext()
{
	var form = document.getElementById('form');
	if (form && form.action)
	{
		return form;
	}

	return {
		action: (typeof window.strBaseURL !== 'undefined' && window.strBaseURL)
			? window.strBaseURL
			: window.location.href
	};
}

function buildProjectEditUrl(projectId)
{
	return createProjectNavigationClient(getProjectNavigationContext()).buildEditUrl(projectId);
}

function createProjectNavigationClient(form)
{
	if (window.PorticoBoundaryClients && typeof window.PorticoBoundaryClients.createProjectClients === 'function')
	{
		return window.PorticoBoundaryClients.createProjectClients(form, {
			parseURL: parseProjectURL
		}).navigation;
	}

	return {
		buildEditUrl: function (id)
		{
			return phpGWLink('index.php', {
				menuaction: 'property.uiproject.edit',
				id: id
			});
		}
	};
}

function createProjectApiClient(form)
{
	if (window.PorticoBoundaryClients && typeof window.PorticoBoundaryClients.createProjectClients === 'function')
	{
		return window.PorticoBoundaryClients.createProjectClients(form, {
			parseURL: parseProjectURL
		}).api;
	}

	return {
		buildSaveRequest: function (currentProjectId)
		{
			var parsedProjectId = parseInt(currentProjectId, 10);
			var requestBase = 'property/project/create';
			var requestMethod = 'POST';
			if (!isNaN(parsedProjectId) && parsedProjectId > 0)
			{
				requestBase = 'property/project/' + parsedProjectId;
				requestMethod = 'PUT';
			}

			return {
				url: phpGWLink(requestBase, {}),
				method: requestMethod
			};
		}
	};
}

function getProjectSaveUrl()
{
	var form = document.form;
	var request = createProjectApiClient(form).buildSaveRequest(project_id);
	if (request && request.url)
	{
		return request.url;
	}

	return phpGWLink('property/project/create', {});
}

function isProjectCopyRequested(form)
{
	var copyProjectField = form ? form.querySelector('input[name="values[copy_project]"]') : null;
	return !!(copyProjectField && copyProjectField.checked);
}

function normalizeProjectSaveRequest(saveRequest, currentProjectId, forceCreate)
{
	var parsedProjectId = parseInt(currentProjectId, 10);
	var isCreate = !!forceCreate || isNaN(parsedProjectId) || parsedProjectId <= 0;
	var method = (saveRequest && saveRequest.method) ? String(saveRequest.method).toUpperCase() : (isCreate ? 'POST' : 'PUT');
	var url = (saveRequest && saveRequest.url) ? saveRequest.url : getProjectSaveUrl();

	if (isCreate)
	{
		method = 'POST';

		if (url && url.indexOf('/property/project/create') === -1)
		{
			url = phpGWLink('property/project/create', {});
		}

		if (!url || url.indexOf('/property/project/create') === -1)
		{
			url = phpGWLink('property/project/create', {});
		}
	}

	return {
		url: url,
		method: method
	};
}

function buildProjectSaveFormData()
{
	var form = document.form;
	var formData = new FormData(form);
	var submitButton = document.getElementsByName('save')[0];
	if (submitButton)
	{
		formData.set('save', submitButton.value || '1');
	}

	return formData;
}

function parseProjectFormKeyTokens(key)
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

function setProjectNestedValue(target, key, value)
{
	var tokens = parseProjectFormKeyTokens(key);
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

function formDataToProjectObject(formData)
{
	var payload = {};
	formData.forEach(function (value, key)
	{
		setProjectNestedValue(payload, key, value);
	});
	return payload;
}

function deriveProjectLocationCode(payloadValues, rawPayload)
{
	var explicitLocationCode = '';

	if (payloadValues && typeof payloadValues.location_code === 'string' && payloadValues.location_code.trim() !== '')
	{
		explicitLocationCode = payloadValues.location_code.trim();
	}
	else if (rawPayload && typeof rawPayload.location_code === 'string' && rawPayload.location_code.trim() !== '')
	{
		explicitLocationCode = rawPayload.location_code.trim();
	}

	if (explicitLocationCode)
	{
		return explicitLocationCode;
	}

	var partsByLevel = {};

	var locationNode = payloadValues && payloadValues.location && typeof payloadValues.location === 'object'
		? payloadValues.location
		: null;
	if (locationNode)
	{
		for (var key in locationNode)
		{
			if (!Object.prototype.hasOwnProperty.call(locationNode, key))
			{
				continue;
			}

			var match = key.match(/^loc(\d+)$/);
			if (!match)
			{
				continue;
			}

			var value = String(locationNode[key] || '').trim();
			if (!value)
			{
				continue;
			}

			var level = parseInt(match[1], 10);
			if (Number.isFinite(level) && level > 0 && !Object.prototype.hasOwnProperty.call(partsByLevel, level))
			{
				partsByLevel[level] = value;
			}
		}
	}

	var nodesToScan = [payloadValues || {}, rawPayload || {}];
	for (var n = 0; n < nodesToScan.length; n++)
	{
		var node = nodesToScan[n];
		for (var nodeKey in node)
		{
			if (!Object.prototype.hasOwnProperty.call(node, nodeKey))
			{
				continue;
			}

			var nodeMatch = nodeKey.match(/^loc(\d+)$/);
			if (!nodeMatch)
			{
				continue;
			}

			var nodeValue = String(node[nodeKey] || '').trim();
			if (!nodeValue)
			{
				continue;
			}

			var nodeLevel = parseInt(nodeMatch[1], 10);
			if (Number.isFinite(nodeLevel) && nodeLevel > 0 && !Object.prototype.hasOwnProperty.call(partsByLevel, nodeLevel))
			{
				partsByLevel[nodeLevel] = nodeValue;
			}
		}
	}

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

	var locationParts = levels.map(function (level)
	{
		return partsByLevel[level];
	});

	return locationParts.join('-');
}

function buildProjectSavePayload(formData)
{
	var rawPayload = formDataToProjectObject(formData);
	var payloadValues = (rawPayload.values && typeof rawPayload.values === 'object') ? rawPayload.values : {};
	var payloadAttributes = (rawPayload.values_attribute && typeof rawPayload.values_attribute === 'object')
		? rawPayload.values_attribute
		: {};
	var relationInfo = (rawPayload.RelationInfo && typeof rawPayload.RelationInfo === 'object')
		? rawPayload.RelationInfo
		: {};
	var relationFields = ['location_code', 'tenant_id', 'p_num', 'p_entity_id', 'p_cat_id', 'origin', 'origin_id'];
	var i;

	// Ensure non-nested inputs (for example "contact") are preserved in values.
	for (var key in rawPayload)
	{
		if (!Object.prototype.hasOwnProperty.call(rawPayload, key))
		{
			continue;
		}
		if (key === 'values' || key === 'values_attribute' || key === 'RelationInfo')
		{
			continue;
		}
		if (!Object.prototype.hasOwnProperty.call(payloadValues, key))
		{
			payloadValues[key] = rawPayload[key];
		}
	}

	if (!Object.prototype.hasOwnProperty.call(payloadValues, 'location_code') || !String(payloadValues.location_code || '').trim())
	{
		var derivedLocationCode = deriveProjectLocationCode(payloadValues, rawPayload);
		if (derivedLocationCode)
		{
			payloadValues.location_code = derivedLocationCode;
		}
	}

	// Normalize relation metadata into dedicated RelationInfo contract.
	for (i = 0; i < relationFields.length; i++)
	{
		var relationField = relationFields[i];
		if (!Object.prototype.hasOwnProperty.call(relationInfo, relationField)
			&& Object.prototype.hasOwnProperty.call(payloadValues, relationField)
		)
		{
			relationInfo[relationField] = payloadValues[relationField];
		}
	}

	return {
		values: payloadValues,
		values_attribute: payloadAttributes,
		RelationInfo: relationInfo
	};
}

function redirectAfterProjectSave(id)
{
	if (!id)
	{
		return;
	}

	var form = document.form;
	var navigation = createProjectNavigationClient(form);
	window.location.href = navigation.buildEditUrl(id);
}

var isProjectSubmitting = false;

function setProjectSubmitButtonsDisabled(disabled)
{
	var buttons = document.querySelectorAll('input[type="submit"], button[type="submit"]');
	for (var i = 0; i < buttons.length; i++)
	{
		buttons[i].disabled = disabled;
	}
}

function extractProjectErrorMessages(responseData)
{
	if (!responseData || !responseData.receipt || !Array.isArray(responseData.receipt.error))
	{
		return [];
	}

	return responseData.receipt.error.map(function (entry)
	{
		if (entry && typeof entry.msg === 'string')
		{
			return entry.msg;
		}
		return '';
	}).filter(function (message)
	{
		return !!message;
	});
}

function clearProjectFormAlerts()
{
	var notices = document.querySelectorAll('.project-submit-alert');
	for (var i = 0; i < notices.length; i++)
	{
		notices[i].remove();
	}
}

function renderProjectFormAlert(messages, type)
{
	var form = document.getElementById('form');
	if (!form)
	{
		window.alert(messages[0] || '');
		return;
	}

	clearProjectFormAlerts();

	var alert = document.createElement('div');
	alert.className = 'project-submit-alert text-center alert alert-' + type;
	alert.setAttribute('role', 'alert');

	for (var i = 0; i < messages.length; i++)
	{
		if (i > 0)
		{
			alert.appendChild(document.createElement('br'));
		}
		alert.appendChild(document.createTextNode(messages[i]));
	}

	form.insertBefore(alert, form.firstChild);
	form.scrollIntoView({behavior: 'smooth', block: 'start'});
}

function renderProjectSaveError(messages)
{
	if (!messages || !messages.length)
	{
		messages = ['Feil ved lagring. Vennligst prøv igjen.'];
	}
	renderProjectFormAlert(messages, 'danger');
}

function check_and_submit_valid_session()
{
	var form = document.form;
	if (isProjectSubmitting)
	{
		return;
	}

	if (!window.fetch)
	{
		document.getElementsByName("save")[0].value = 1;
		form.submit();
		return;
	}

	var formData = buildProjectSaveFormData();
	var payload = buildProjectSavePayload(formData);
	var copyProjectRequested = isProjectCopyRequested(form);
	var saveRequestRaw = createProjectApiClient(form).buildSaveRequest(project_id);
	var saveRequest = normalizeProjectSaveRequest(saveRequestRaw, project_id, copyProjectRequested);
	var requestUrl = saveRequest.url;
	var projectId = Number(project_id);
	var isCreate = copyProjectRequested || !projectId;
	var requestOptions = {
		method: saveRequest.method ? saveRequest.method : (isCreate ? 'POST' : 'PUT'),
		credentials: 'same-origin'
	};

	if (form.querySelector('input[type="file"][name="file"]') || form.querySelector('input[type="file"][name="jasperfile"]'))
	{
		requestOptions.body = formData;
	}
	else
	{
		requestOptions.headers = {'Content-Type': 'application/json'};
		requestOptions.body = JSON.stringify(payload);
	}

	isProjectSubmitting = true;
	setProjectSubmitButtonsDisabled(true);

	fetch(requestUrl, requestOptions)
		.then(function (response)
		{
			return response.json().catch(function ()
			{
				return null;
			}).then(function (data)
			{
				if (!response.ok)
				{
					var error = new Error('Project save failed');
					error.responseData = data;
					throw error;
				}

				return data;
			});
		})
		.then(function (data)
		{
			var id = (data && data.data && data.data.id) ? data.data.id : (data && data.id ? data.id : project_id);
			if (data && data.receipt && data.receipt.error && data.receipt.error.length)
			{
				throw {responseData: data};
			}
			var successMsg = isCreate ? 'Prosjektet er opprettet' : 'Prosjektet er lagret';
			renderProjectFormAlert([successMsg], 'success');
			setTimeout(function ()
			{
				redirectAfterProjectSave(id);
			}, 1200);
		})
		.catch(function (error)
		{
			isProjectSubmitting = false;
			setProjectSubmitButtonsDisabled(false);
			renderProjectSaveError(extractProjectErrorMessages(error && error.responseData));
		});
}

this.validate_form = function ()
{
	conf = {
		//	modules: 'date, security, file',
		validateOnBlur: false,
		scrollToTopOnError: true,
		errorMessagePosition: 'top'
	};

	return $('form').isValid(false, conf);
}

JqueryPortico.FormatterClosed = function (key, oData)
{
	return "<div align=\"center\">" + oData['closed'] + oData['closed_orig'] + "</div>";
};

JqueryPortico.FormatterActive = function (key, oData)
{
	return "<div align=\"center\">" + oData['active'] + oData['active_orig'] + "</div>";
};

function set_tab(active_tab)
{
//	var test = $('#tab-content').responsiveTabs('activate');
//	alert(test);
//console.log(test);
	$("#active_tab").val(active_tab);
	check_button_names();
}

check_button_names = function ()
{
	var active_tab = $("#active_tab").val();

	if (Number(project_id) === 0)
	{
		if (active_tab === 'location')
		{
			$("#submitform").val(lang['next']);
		}
		else if (active_tab === 'general')
		{
			$("#submitform").val(lang['next']);
		}
		else
		{
			$("#submitform").val(lang['save']);
		}
	}
};

validate_submit = function ()
{
	var active_tab = $("#active_tab").val();

	if (!validate_form())
	{
		return;
	}

	if (active_tab === 'location' && Number(project_id) === 0)
	{
		$('#tab-content').responsiveTabs('enable', 1);
		$('#tab-content').responsiveTabs('activate', 1);
		$("#submitform").val(lang['next']);
		$("#active_tab").val('general');
	}
	else if (active_tab === 'general' && Number(project_id) === 0)
	{
		$('#tab-content').responsiveTabs('enable', 2);
		$('#tab-content').responsiveTabs('activate', 2);
		$("#submitform").val(lang['save']);
		$("#active_tab").val('budget');
	}
	else
	{
		check_and_submit_valid_session();
	}

};

//$(document).ready(function ()
//{
//
//	$('form[name=form]').submit(function (e)
//	{
//		e.preventDefault();
//
//	});
//});


$(window).on('load', function ()
{


	$("#external_project_name").on("autocompleteselect", function (event, ui)
	{
		var external_project_id = ui.item.value;

		check_valid_external_project(external_project_id);

	});


	function check_valid_external_project(external_project_id)
	{

		var strURL = phpGWLink('property/project/lookups/external-project', {
			query: external_project_id,
			phpgw_return_as: 'json'
		}, true);
		

		$.getJSON(strURL, function (Result)
		{
			if (Result.ResultSet.Result.length > 0)
			{
				var external_project = Result.ResultSet.Result[0];
				external_project_budget_account_category = external_project.b_account_category;

			}
		});
		
	}
});


window.on_location_updated = function (location_code)
{
	location_code = location_code || $("#loc1").val();

	get_location_exception(location_code);

	get_other_projects(location_code);

	load_google_map();

	if ($("#delivery_address").val())
	{
		return;
	}

	var oArgs = {loc1: location_code};
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
    //delete div where role=alert, not the $("#message")
    $("div[role=alert]").remove();

	var oArgs = {location_code: location_code};
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
	var sUrl = phpGWLink('property/project/' + encodeURIComponent(project_id) + '/multi-upload', {});
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
	var strURL = phpGWLink('property/project/' + project_id + '/files', {
		phpgw_return_as: 'json'
	}, true);

	refresh_glider(strURL);

	JqueryPortico.updateinlineTableHelper(oTable5, strURL);
};

this.get_other_projects = function (location_code)
{
	var oArgs = {location_code: location_code, id: project_id};
	var strURL = phpGWLink('property/project/' + project_id + '/other-projects', oArgs);
	JqueryPortico.updateinlineTableHelper('datatable-container_7', strURL);
};

this.load_google_map = function (location_code)
{

	var street_name = $("#street_name").val();
	var street_number = $("#street_number").val();
	var address = street_name + ' ' + street_number;
	var iurl = 'https://maps.google.com/maps?f=q&source=s_q&hl=no&output=embed&geocode=&q=' + address;
	var linkurl = 'https://maps.google.com/maps?f=q&source=s_q&hl=no&geocode=&q=' + address;
	if (typeof (street_name) != 'undefined' && address.length > 1)
	{
		$("#gmap-container").show();
		$("#googlemapiframe").attr("src", iurl);
		$("#googlemaplink").attr("href", linkurl);
	}
	else
	{
		$("#gmap-container").hide();
	}
};