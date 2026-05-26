/* global lang, event_id */

var building_id_selection = "";
$(document).ready(function ()
{
	var $form = $('#form');
	var validationSubmitted = false;
	var errorColor = '#d9534f';
	var okColor = '#28a745';

	$form.attr('novalidate', 'novalidate');

	function getValidationMessage($field, fallbackMessage)
	{
		return $field.attr('data-validation-error-msg') || fallbackMessage;
	}

	function getValidationFeedbackElement($field)
	{
		var $feedback = $field.data('validationFeedback');

		if (!$feedback || !$feedback.length)
		{
			$feedback = $('<div class="validation-status"></div>');
			if ($field.hasClass('select2-hidden-accessible'))
			{
				$field.next('.select2').after($feedback);
			}
			else
			{
				$field.after($feedback);
			}
			$field.data('validationFeedback', $feedback);
		}

		return $feedback;
	}

	function updateFieldState($field, isValid, message)
	{
		var $target = $field;
		var $feedback = getValidationFeedbackElement($field);

		if ($field.hasClass('select2-hidden-accessible'))
		{
			$target = $field.next('.select2').find('.select2-selection');
		}

		$field.toggleClass('error', !isValid).toggleClass('ok', isValid);
		$target.toggleClass('error', !isValid).toggleClass('ok', isValid).css('border-color', isValid ? okColor : errorColor);
		$feedback.text(isValid ? 'ok' : message).css({
			color: isValid ? okColor : errorColor,
			marginTop: '0.25rem',
			display: 'block'
		});
	}

	function validateRequiredField($field)
	{
		var value = $field.val();
		var isValid;

		if ($field.is('select[multiple]'))
		{
			isValid = Array.isArray(value) ? value.length > 0 : !!value;
		}
		else
		{
			isValid = !!String(value || '').trim();
		}

		updateFieldState($field, isValid, getValidationMessage($field, 'This field is required'));
		return isValid;
	}

	function updateResourcesState(isValid)
	{
		var $container = $('#resources_container');
		var $table = $container.find('table');
		var $indicator = $container.find('.validation-status');
		var message = getValidationMessage(
			$('input[data-validation="application_resources"]'),
			'Please choose at least 1 resource'
		);

		if (!$indicator.length)
		{
			$indicator = $('<div class="validation-status"></div>');
			$container.append($indicator);
		}

		$table.toggleClass('error', !isValid).toggleClass('ok', isValid).css('border-color', isValid ? okColor : errorColor);
		$indicator.text(isValid ? 'ok' : message).css({
			color: isValid ? okColor : errorColor,
			marginTop: '0.25rem',
			display: 'block'
		});
	}

	function validateResourcesSelection()
	{
		var isValid = $('#resources_container input[name="resources[]"]:checked').length > 0;
		updateResourcesState(isValid);
		return isValid;
	}

	function validateForm()
	{
		var isValid = true;

		$form.find('[required]').each(function ()
		{
			if (!validateRequiredField($(this)))
			{
				isValid = false;
			}
		});

		if (!validateResourcesSelection())
		{
			isValid = false;
		}

		return isValid;
	}

	$form.on('submit', function (event)
	{
		var $firstInvalid;
		validationSubmitted = true;

		if (!validateForm())
		{
			event.preventDefault();
			$firstInvalid = $form.find('[required].error').first();
			if ($firstInvalid.length)
			{
				if ($firstInvalid.hasClass('select2-hidden-accessible'))
				{
					$firstInvalid.select2('open');
				}
				else
				{
					$firstInvalid.trigger('focus');
				}
			}
			return false;
		}
	});

	$form.find('[required]').on('input change', function ()
	{
		if (validationSubmitted)
		{
			validateRequiredField($(this));
		}
	});

	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'booking.uibuilding.index'}, true),
		'field_building_name', 'field_building_id', 'building_container');

	$("#field_activities").select2({
			placeholder: lang['Activities'],
			width: '100%'
		});

	$('#field_activities').on('select2:open', function (e) {

		$(".select2-search__field").each(function()
		{
			if ($(this).attr("aria-controls") == 'select2-field_activities-results')
			{
				$(this)[0].focus();
			}
		});
	});

	var oArgs = {menuaction: 'preferences.boadmin_acl.get_users'};
	var strURL = phpGWLink('index.php', oArgs, true);

	$("#field_owner_id").select2({
		ajax: {
		url: strURL,
		dataType: 'json',
		delay: 250,
		data: function (params) {
			return {
			query: params.term, // search term
			page: params.page || 1
			};
		},
		cache: true
		},
		width: '100%',
		placeholder: lang['Owner'],
		minimumInputLength: 2,
		language: "no",
		allowClear: true
	});

	$('#field_owner_id').on('select2:open', function (e) {

		$(".select2-search__field").each(function()
		{
			if ($(this).attr("aria-controls") == 'select2-field_owner_id-results')
			{
				$(this)[0].focus();
			}
		});
	});

	
	//on change of the select field_entities, look up category and set field_category options accordingly
	$("#field_entities").on("change", function (event)
	{
		var entity_id = $(this).val();
		if (entity_id)
		{
			var url = phpGWLink('index.php', {menuaction: 'booking.uiresource_activity_entityform.get_categories', entity_id: entity_id}, true);
			$.getJSON(url, function (data)
			{
				var options = '<option value="">' + lang['Select'] + '</option>';
				$.each(data, function (index, category)
				{
					options += '<option value="' + category.location_id + '">' + category.name + '</option>';
				});
				$("#field_category").html(options);
			});
		}
		else
		{
			$("#field_category").html('<option value="">' + lang['Select'] + '</option>');
		}
	});
	
});

$(window).on('load', function ()
{
	var building_id = $('#field_building_id').val();
	if (building_id)
	{
		populateTableChkResources(building_id, initialSelection);
		building_id_selection = building_id;
	}
	$("#field_building_name").on("autocompleteselect", function (event, ui)
	{
		var building_id = ui.item.value;
		if (building_id != building_id_selection)
		{
			populateTableChkResources(building_id, []);
			building_id_selection = building_id;
		}
	});
	

	$('#resources_container').on('change', '.chkRegulations', function ()
	{
		var resources = [];
		$('#resources_container input[name="resources[]"]:checked').each(function ()
		{
			resources.push($(this).val());
		});

		if (validationSubmitted)
		{
			validateResourcesSelection();
		}
	});


});


function populateTableChkResources(building_id, selection)
{
	var url = phpGWLink('index.php', {menuaction: 'booking.uiresource.index', sort: 'name', filter_building_id: building_id, length: -1}, true);
	var container = 'resources_container';
	var colDefsResources = [{label: '', object: [{type: 'input', attrs: [
						{name: 'type', value: 'checkbox'}, {name: 'name', value: 'resources[]'}, {name: 'class', value: 'chkRegulations'}
					]}
			], value: 'id', checked: selection}, {key: 'name', label: lang['Name']}, {key: 'rescategory_name', label: lang['Resource Type']}
	];
	populateTableChk(url, container, colDefsResources);
}

function populateTableChk(url, container, colDefs)
{
	createTable(container, url, colDefs, '', 'pure-table pure-table-bordered');
}
