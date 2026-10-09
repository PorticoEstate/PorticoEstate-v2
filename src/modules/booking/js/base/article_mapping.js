/* global initialSelection, lang, tax_percentages */

var building_id_selection = "";
var resource_id_selection = "";
var service_id_selected = "";
$(document).ready(function ()
{

	$.formUtils.addValidator({
		name: 'new_price',
		validatorFunction: function (value, $el, config, languaje, $form)
		{
			var v = true;
			var date_from = $('#date_from').val();
			var price = $('#price').val();
			if ((date_from == "" && price != "") || (date_from != "" && price == "") )
			{
				v = false;
			}
			return v;
		},
		errorMessage: 'please enter both a date and prize',
		errorMessageKey: ''
	});

	$.formUtils.addValidator({
		name: 'article_price',
		validatorFunction: function (value)
		{
			return article_price.parseCents(value) !== null;
		},
		errorMessage: '',
		errorMessageKey: ''
	});

	// A stored price's fields are only posted once named, so an unnamed one
	// was left as it is and passes
	$.formUtils.addValidator({
		name: 'article_price_row',
		validatorFunction: function (value, $el)
		{
			return !$el.attr('name') || article_price.parseCents(value) !== null;
		},
		errorMessage: '',
		errorMessageKey: ''
	});

	$.formUtils.addValidator({
		name: 'article_price_from',
		validatorFunction: function (value, $el)
		{
			return !$el.attr('name') || article_price.parseDate(value) !== null;
		},
		errorMessage: '',
		errorMessageKey: ''
	});

	$('#price').on('input', function ()
	{
		fill_price_incl($('#price'), $('#price_incl'), $('#price_incl_note'));
	});
	$('#price_incl').on('input', function ()
	{
		fill_price_ex($('#price'), $('#price_incl'), $('#price_incl_note'));
	});
	$('#price, #price_incl').on('change', show_canonical_price);

	// The prices already stored, in the history table: a field gets the name
	// it is posted under once it is edited, and not before
	$('#prizing').on('input change', '.price-row-ex, .price-row-from, .price-row-remark', function ()
	{
		name_price_field($(this));
	});
	$('#prizing').on('input', '.price-row-ex', function ()
	{
		var row = $(this).closest('tr');
		fill_price_incl(row.find('.price-row-ex'), row.find('.price-row-incl'), row.find('.price-row-incl-note'));
	});
	$('#prizing').on('input', '.price-row-incl', function ()
	{
		var row = $(this).closest('tr');
		fill_price_ex(row.find('.price-row-ex'), row.find('.price-row-incl'), row.find('.price-row-incl-note'));
	});
	$('#prizing').on('change', '.price-row-ex, .price-row-incl', show_canonical_price);
	$('#prizing').on('focus', '.price-row-from', function ()
	{
		var from = $(this);
		if (from.data('xdsoft_datetimepicker'))
		{
			return;
		}
		from.datetimepicker({
			format: 'Y-m-d',
			datepicker: true,
			timepicker: false,
			weeks: true,
			dayOfWeekStart: 1,
			// Left alone, the picker puts today in place of a date it cannot
			// read and turns the date as the mouse wheel passes over it
			validateOnBlur: false,
			scrollInput: false,
			// Only with this set does a picked date fire 'change' on the input
			onChangeDateTime: function ()
			{
			}
		}).datetimepicker('show');
	});

	$('#tax_code').on('change', function ()
	{
		show_prizing_tax(true);
	});
	show_prizing_tax(false);


	$("#field_article_cat_id").change(function ()
	{
		if ($(this).val() == 2) //service
		{
			$('#service_container').show();
			$('#resource_selector').hide();
			get_services();
		}
		else
		{
			$('#resource_selector').show();
			$('#service_container').hide();
		}

	});

	$("#field_service_id").change(function ()
	{
		service_id_selected = $(this).val();
	});

	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'booking.uibuilding.index'}, true),
		'field_building_name', 'field_building_id', 'building_container');

});

/**
 * Prising: a price is typed and stored ex. VAT, and the VAT-inclusive field
 * beside it is worked out from the tax code and never posted. Parsing and
 * rounding are those of App\modules\booking\helpers\ArticlePriceInput, so
 * the figures shown while typing are the ones stored; change both or neither.
 */
var article_price = {
	maxIntegerDigits: 8,

	/**
	 * A typed price in øre, or null when it is not a price. The last '.' or
	 * ',' is the decimal mark when one or two digits follow it; every other
	 * separator, and whitespace, groups thousands.
	 */
	parseCents: function (input)
	{
		var value = String(input === null || input === undefined ? '' : input).replace(/[ \t\n\r\f\v   ]+/g, '');
		var matches = value.match(/^([+-]?)([0-9.,]*[0-9][0-9.,]*)$/);
		if (!matches)
		{
			return null;
		}

		var integer = matches[2];
		var fraction = '';
		var parts = matches[2].match(/^(.*)[.,]([0-9]{1,2})$/);
		if (parts)
		{
			integer = parts[1];
			fraction = parts[2];
		}

		if (/[.,]/.test(integer) && !/^[1-9][0-9]{0,2}([.,][0-9]{3})+$/.test(integer))
		{
			return null;
		}

		integer = integer.replace(/[.,]/g, '').replace(/^0+/, '');
		if (integer.length > this.maxIntegerDigits)
		{
			return null;
		}

		var cents = Number(integer || '0') * 100 + Number((fraction + '00').slice(0, 2));
		return matches[1] === '-' ? -cents : cents;
	},

	/**
	 * A typed "valid from" date as YYYY-MM-DD, or null when it is not one.
	 * Trims as PHP's trim() does, and accepts what PHP's checkdate() does.
	 */
	parseDate: function (input)
	{
		var value = String(input === null || input === undefined ? '' : input).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
		var matches = value.match(/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/);
		if (!matches)
		{
			return null;
		}

		var year = Number(matches[1]);
		var month = Number(matches[2]);
		var day = Number(matches[3]);
		var leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
		var days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

		if (year < 1 || month < 1 || month > 12 || day < 1 || day > days[month - 1])
		{
			return null;
		}
		return value;
	},

	/** Øre as a decimal string with two decimals and no grouping */
	format: function (cents, decimalMark)
	{
		var magnitude = Math.abs(cents);
		var fraction = String(magnitude % 100);
		return (cents < 0 ? '-' : '') + Math.floor(magnitude / 100) + decimalMark + (fraction.length < 2 ? '0' + fraction : fraction);
	},

	/** Ex. to incl. VAT, half away from zero to whole øre, as billed */
	inclVatCents: function (exCents, percent)
	{
		var magnitude = Math.floor((Math.abs(exCents) * (100 + percent) * 2 + 100) / 200);
		return exCents < 0 ? -magnitude : magnitude;
	},

	/** Incl. to ex. VAT, half away from zero to whole øre */
	exVatCents: function (inclCents, percent)
	{
		var magnitude = Math.floor((Math.abs(inclCents) * 200 + (100 + percent)) / (2 * (100 + percent)));
		return inclCents < 0 ? -magnitude : magnitude;
	}
};

/** The rate of the tax code selected on the article tab, or null without one */
function prizing_tax_percent()
{
	var code = $('#tax_code').val();
	if (typeof tax_percentages === 'undefined' || code === null || code === '' || !(code in tax_percentages) || tax_percentages[code] === null)
	{
		return null;
	}
	return Number(tax_percentages[code]);
}

function show_prizing_tax(recalculate)
{
	var percent = prizing_tax_percent();
	var name = $('#tax_code option:selected').text();

	$('#prizing_tax_code').text(percent === null ? name + ' – ' + lang['vat_rate_missing'] : name + ' (' + percent + ' %)');
	$('#price_incl, #datatable-container_0 .price-row-incl').prop('disabled', percent === null);

	if (!recalculate)
	{
		return;
	}

	fill_price_incl($('#price'), $('#price_incl'), $('#price_incl_note'));
	$('#datatable-container_0 tr').has('.price-row-ex').each(function ()
	{
		var row = $(this);
		fill_price_incl(row.find('.price-row-ex'), row.find('.price-row-incl'), row.find('.price-row-incl-note'));
	});
}

/** A stored price's field, edited: posted from now on */
function name_price_field(field)
{
	field.attr('name', field.attr('data-name'));
}

/** Rewrite a price as it is read, so the admin sees what will be stored */
function show_canonical_price()
{
	var cents = article_price.parseCents($(this).val());
	if (cents !== null)
	{
		$(this).val(article_price.format(cents, ','));
	}
}

/** Fill the price incl. VAT from the price ex. VAT beside it */
function fill_price_incl(ex, incl, note)
{
	var percent = prizing_tax_percent();
	var ex_cents = article_price.parseCents(ex.val());

	note.prop('hidden', true);
	incl.val(percent === null || ex_cents === null ? '' : article_price.format(article_price.inclVatCents(ex_cents, percent), ','));
}

/** Fill the price ex. VAT from the price incl. VAT beside it */
function fill_price_ex(ex, incl, note)
{
	var percent = prizing_tax_percent();
	var incl_cents = article_price.parseCents(incl.val());
	note.prop('hidden', true);

	if (percent === null)
	{
		return;
	}
	if (ex.attr('data-name'))
	{
		name_price_field(ex);
	}
	if (incl_cents === null)
	{
		ex.val('');
		return;
	}

	var ex_cents = article_price.exVatCents(incl_cents, percent);
	ex.val(article_price.format(ex_cents, ','));

	// At 25 %, one øre amount in five has no price ex. VAT that gives it
	var reached = article_price.inclVatCents(ex_cents, percent);
	if (reached !== incl_cents)
	{
		note.text(lang['price_incl_vat_nearest'] + ' ' + article_price.format(reached, ',')).prop('hidden', false);
	}
}

function set_tab(tab)
{
	$("#active_tab").val(tab);
	check_button_names();
}

check_button_names = function ()
{
	var tab = $("#active_tab").val();
	var id = $("#id").val();

	if (id > 0)
	{
		$("#submit_group_bottom").show();
	}


	if (tab === 'first_tab')
	{
		if (id > 0)
		{
			$("#save_button_bottom").val(lang['save']);
		}
		else
		{
			$("#save_button_bottom").val(lang['next']);
		}
	}
	else if (tab === 'prizing')
	{
		$("#save_button_bottom").val(lang['next']);
	}
	else if (tab === 'override')
	{
		$("#save_button_bottom").val(lang['save']);
	}
	else
	{
		$("#save_button").val(lang['save']);
		$("#submit_group_bottom").hide();
	}
};

function get_services()
{
	var oArgs = {menuaction: 'booking.uiarticle_mapping.get_services'};
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
				htmlString = "<option value=''>" + lang['Select'] + "</option>"

				$.each(data, function (i)
				{

					var selected = '';
					if (data[i].id == service_id_selected)
					{
						selected = ' selected';
					}
					htmlString += "<option value='" + data[i].id + "'" + selected + ">" + data[i].name + "</option>";
				});

			}
			else
			{
				htmlString += "<option>Velg</option>"
			}

			$("#field_service_id").html(htmlString);
		}
	});
}




$(window).on('load', function ()
{
	var article_cat_id = $('#field_article_cat_id').val();
	resource_id_selection = initialSelection[0];

	if (article_cat_id == 2) //service
	{
		$('#service_container').show();
	}
	else
	{
		$('#resource_selector').show();

	}

	var building_id = $('#field_building_id').val();
	if (building_id && building_id > 0)
	{
		populateTableChkResources_init(callback_reserved, building_id, initialSelection);
		building_id_selection = building_id;
	}
	$("#field_building_name").on("autocompleteselect", function (event, ui)
	{
		var building_id = ui.item.value;
		if (building_id != building_id_selection)
		{
			var selection = [];

			if (resource_id_selection)
			{
				selection.push(resource_id_selection);
			}
			populateTableChkResources_init(callback_reserved, building_id, selection);
			building_id_selection = building_id;
		}
	});

	$('#resources_container').on('change', '.chkRegulations', function ()
	{
		$('#resources_container input.chkRegulations[name="resource_id"]:checked').each(function ()
		{
			resource_id_selection = $(this).val();
		});
	});

});

var callback_reserved = function (building_id, selection, data)
{
	var disabled = [];
	if (data != null)
	{
		$.each(data, function (i)
		{
			if (!selection.includes(data[i]))
			{
				disabled.push(data[i]);
			}
		});
	}
	populateTableChkResources(building_id, selection, disabled);
};


function populateTableChkResources_init(callback, building_id, selection)
{
	var oArgs = {menuaction: 'booking.uiarticle_mapping.get_reserved_resources', building_id: building_id};
	var requestUrl = phpGWLink('index.php', oArgs, true);

	$.ajax({
		type: 'POST',
		dataType: 'json',
		url: requestUrl,
		success: function (result)
		{
			callback(building_id, selection, result);
		}
	});

}


function populateTableChkResources(building_id, selection, disabled)
{
	console.log(building_id);
	console.log(selection);
	console.log(disabled);
	var url = phpGWLink('index.php', {menuaction: 'booking.uiresource.index', sort: 'name', filter_building_id: building_id, length: -1, filter_active: -1}, true);
	var container = 'resources_container';
	var colDefsResources = [
		{label: lang['Select'], object: [
				{type: 'input', attrs: [
						{name: 'type', value: 'radio'},
						{name: 'name', value: 'resource_id'},
						{name: 'data-validation', value: 'required'},
						{name: 'data-validation-qty', value: 'min1'},
						{name: 'data-validation-error-msg', value: 'Please choose a resource'},
						{name: 'class', value: 'chkRegulations'}
					]
				}
			],
			value: 'id',
			checked: selection,
			disabled: disabled
		},
		{key: 'name', label: lang['Name']},
		{key: 'rescategory_name', label: lang['Resource Type']},
		{key: 'active', label: lang['Active']}
	];
	populateTableChk(url, container, colDefsResources);
}

function populateTableChk(url, container, colDefs)
{
	createTable(container, url, colDefs, '', 'pure-table pure-table-bordered');
}

function local_custom_radio_action(radio)
{
	console.log($(radio).val());
}

validate_submit = function ()
{
	var active_tab = $("#active_tab").val();
	conf = {
		//	modules: 'date, security, file',
		validateOnBlur: false,
		scrollToTopOnError: true,
		errorMessagePosition: 'top'
	};

	var test = $('form').isValid(false, conf);
	if (!test)
	{
		return;
	}


	if ($("#field_article_cat_id").val() == 1) //resource
	{
		if (!resource_id_selection)
		{
			alert('Velg bygg - så ressurs');
			return;
		}
	}

	var id = $("#id").val();

	if (id > 0)
	{
		document.form.submit();
		return;
	}

	if (active_tab === 'first_tab')
	{
		$('#tab-content').responsiveTabs('enable', 1);
		$('#tab-content').responsiveTabs('activate', 1);
		$("#save_button_bottom").val(lang['next']);
		$("#active_tab").val('prizing');
		document.form.submit();
	}
	else if (active_tab === 'prizing')
	{
		$('#tab-content').responsiveTabs('enable', 2);
		$('#tab-content').responsiveTabs('activate', 2);
		$("#save_button").val(lang['next']);
		$("#save_button_bottom").val(lang['next']);
		$("#active_tab").val('files');
		document.form.submit();
	}
	else
	{
		document.form.submit();
	}
};

this.refresh_files = function ()
{
	JqueryPortico.updateinlineTableHelper(oTable1);
};
