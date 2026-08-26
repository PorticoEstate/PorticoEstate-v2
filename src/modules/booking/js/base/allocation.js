var building_id_selection = "";
$(document).ready(function ()
{

	$("#field_from").change(function ()
	{
		var temp_field_to = $("#field_to").datetimepicker('getValue');
		var temp_field_from = $("#field_from").datetimepicker('getValue');
		if (!temp_field_to || (temp_field_to < temp_field_from))
		{
			$("#field_to").val($("#field_from").val());

			$('#field_to').datetimepicker('setOptions', {
				startDate: new Date(temp_field_from)
			});
		}
	});

	/**
	 * Update quantity related to time
	 */
	$("#dates-container").on("change", ".datetime", function (event)
	{
		if (typeof (post_handle_order_table) !== 'undefined')
		{
			event.preventDefault();
			post_handle_order_table();
		}

	});

	$('#field_cost_comment').hide();
	$('#field_cost').on('input propertychange paste', function ()
	{
		if ($('#field_cost').val() != $('#field_cost_orig').val())
		{
			$('#field_cost_comment').show();
		}
		else
		{
			$('#field_cost_comment').hide();
		}
	});

	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'booking.uibuilding.index'}, true),
		'field_building_name', 'field_building_id', 'building_container');

	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'booking.uiorganization.index', filter_active: 1}, true),
		'field_org_name', 'field_org_id', 'org_container');
});


$(window).on('load', function ()
{
	var building_id = $('#field_building_id').val();
	if (building_id)
	{
		populateSelectSeason(building_id, season_id);
		populateTableChkResources(building_id, initialSelection);
		building_id_selection = building_id;
	}
	$("#field_building_name").on("autocompleteselect", function (event, ui)
	{
		var building_id = ui.item.value;
		if (building_id != building_id_selection)
		{
			populateSelectSeason(building_id, '');
			populateTableChkResources(building_id, []);
			building_id_selection = building_id;
		}
	});

	$('#resources_container').on('change', '.chkRegulations', function ()
	{
		var resources = new Array();
		$('#resources_container input[name="resources[]"]:checked').each(function ()
		{
			resources.push($(this).val());
		});

		if (typeof (application_id) === 'undefined')
		{
			application_id = '';
		}
		if (typeof (reservation_type) === 'undefined')
		{
			reservation_type = '';
		}
		if (typeof (reservation_id) === 'undefined')
		{
			reservation_id = '';
		}

		if (typeof (populateTableChkArticles) !== 'undefined')
		{

			populateTableChkArticles([
			], resources, application_id, reservation_type, reservation_id);
		}
	});

});

function populateSelectSeason(building_id, selection)
{
	var url = phpGWLink('index.php', {menuaction: 'booking.uiseason.index', sort: 'name', filter_building_id: building_id, filter_now: 1, length: -1}, true);
	var container = $('#season_container');
	var attr = [
		{name: 'name', value: 'season_id'},
		{name: 'data-validation', value: 'required'},
		{name: 'data-validation-error-msg', value: 'Please select a season'},
		{name: 'class', value: 'pure-u-1-4'}
	];
	populateSelect(url, selection, container, attr);
}
function populateTableChkResources(building_id, selection)
{
	var url = phpGWLink('index.php', {menuaction: 'booking.uiresource.index', sort: 'name', filter_building_id: building_id, length: -1}, true);
	var container = 'resources_container';
	var colDefsResources = [{label: '', object: [{type: 'input', attrs: [
						{name: 'type', value: 'checkbox'}, {name: 'name', value: 'resources[]'}, {name: 'class', value: 'chkRegulations'}, {name: 'data-validation', value: 'checkbox_group'}, {name: 'data-validation-qty', value: 'min1'}, {name: 'data-validation-error-msg', value: 'Please choose at least 1 resource'}
					]}
			], value: 'id', checked: selection}, {key: 'name', label: lang['Name']}, {key: 'rescategory_name', label: lang['Resource Type']}
	];
	populateTableChk(url, container, colDefsResources);
}

function populateTableChk(url, container, colDefs)
{
	createTable(container, url, colDefs, '', 'pure-table pure-table-bordered');
}


/**
 * The price dialogs.
 *
 * An allocation that belongs to a recurrence group shares its price with the
 * rest of the series, so a price change has two questions to answer before it
 * is allowed to leave the form: how far the new price should reach, and what to
 * do about occurrences somebody priced by hand.
 *
 * Nothing here decides anything. The answers are posted back as cascade_scope
 * and cascade_overwrite_locked, and the server makes the same decision over
 * again from the stored row - see booking_uiallocation::edit(). This file only
 * asks the questions, and it asks them with counts the server supplied.
 */
var allocationCascadeAnswered = false;

function allocationPriceDialog(title, body, choices)
{
	var dialog = document.createElement('dialog');
	dialog.className = 'allocation-price-dialog';
	dialog.style.maxWidth = '32em';

	var heading = document.createElement('h2');
	heading.textContent = title;
	dialog.appendChild(heading);

	var text = document.createElement('p');
	text.textContent = body;
	dialog.appendChild(text);

	var buttons = document.createElement('div');
	buttons.className = 'form-buttons';

	choices.forEach(function (choice)
	{
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'pure-button' + (choice.primary ? ' pure-button-primary' : '');
		button.textContent = choice.label;
		// Anything reading this dialog back - a test, a later maintainer - needs to
		// tell the buttons apart by something steadier than translated text.
		button.setAttribute('data-cascade-choice', choice.name);
		button.addEventListener('click', function ()
		{
			dialog.close();
			dialog.remove();
			choice.chosen();
		});
		buttons.appendChild(button);
	});

	dialog.appendChild(buttons);
	document.body.appendChild(dialog);
	dialog.showModal();

	return dialog;
}

/**
 * Send the form with the officer's answer attached. A scope of null means there
 * was nothing to ask - the allocation is not part of a series, or the preview
 * could not be reached - and the form goes exactly as it went before these
 * dialogs existed, carrying no extra fields at all.
 */
function allocationSubmitWithScope(scope, overwrite_locked)
{
	var form = document.getElementById('form');

	if (scope !== null)
	{
		$('<input>').attr({type: 'hidden', name: 'cascade_scope', value: scope}).appendTo(form);
		$('<input>').attr({type: 'hidden', name: 'cascade_overwrite_locked', value: overwrite_locked ? 1 : 0}).appendTo(form);
	}

	allocationCascadeAnswered = true;
	// The native submit, not jQuery's: the form has already been past the
	// validator on its way into the dialog, and this must not hand it back to a
	// submit handler that would ask the same questions a second time.
	form.submit();
}

/**
 * The second dialog, and it only appears when there is something to warn about.
 * A scope holding no hand-set prices has no conflict to resolve, so the save
 * goes straight through.
 */
function allocationAskConflict(labels, scope, counts, cancelled)
{
	if (!counts.locked)
	{
		allocationSubmitWithScope(scope, false);
		return;
	}

	allocationPriceDialog(
		// The sentence arrives already written, numbers and all - see
		// booking_uiallocation::cascade_preview(). Nothing is counted here.
		labels.conflict_title,
		counts.conflict_body,
		[
			{name: 'overwrite_all', label: labels.overwrite_all, chosen: function ()
				{
					allocationSubmitWithScope(scope, true);
				}},
			{name: 'keep_manual', label: labels.keep_manual, primary: true, chosen: function ()
				{
					allocationSubmitWithScope(scope, false);
				}},
			{name: 'cancel', label: labels.cancel, chosen: cancelled}
		]
	);
}

/**
 * The first dialog. "Oppdater dette" is the answer that reaches nothing, so it
 * can never raise a conflict and goes straight to the save.
 */
function allocationAskScope(preview)
{
	var labels = preview.labels;
	var scopes = preview.scopes;
	var abandon = function ()
	{
	};

	// The reach rides on the label, so the officer can see how many occurrences
	// an answer moves before he picks it.
	var withCount = function (label, scope)
	{
		return scopes[scope].total ? label + ' (' + scopes[scope].total + ')' : label;
	};

	allocationPriceDialog(labels.scope_title, labels.scope_body, [
		{name: 'this', label: labels.scope_this, chosen: function ()
			{
				allocationSubmitWithScope('this', false);
			}},
		{name: 'future', label: withCount(labels.scope_future, 'future'), chosen: function ()
			{
				allocationAskConflict(labels, 'future', scopes.future, abandon);
			}},
		{name: 'all', label: withCount(labels.scope_all, 'all'), primary: true, chosen: function ()
			{
				allocationAskConflict(labels, 'all', scopes.all, abandon);
			}},
		{name: 'cancel', label: labels.cancel, chosen: abandon}
	]);
}

// Bound on window load rather than document ready so that it lands after the
// handlers every ready callback has already registered.
$(window).on('load', function ()
{
	$('#form').on('submit', function (event)
	{
		if (allocationCascadeAnswered || event.isDefaultPrevented())
		{
			return;
		}

		// Numerically, never as strings. With articles on, purchase_order_edit.js
		// rewrites this field from the article lines as soon as the page settles, so
		// a form nobody has touched holds "500.00" against a cost_orig of "500" -
		// the same price, different text, and a string comparison would put a dialog
		// in front of every save on the page.
		//
		// This only decides whether to ASK. Whether anything is written is decided
		// again on the server, against the stored row, and the two need not agree:
		// asking one question too many costs a click, and the server still refuses
		// to cascade a price that did not move.
		var shown_cost = parseFloat($('#field_cost').val());
		var stored_cost = parseFloat($('#field_cost_orig').val());

		if (isNaN(shown_cost) || shown_cost === stored_cost)
		{
			return;
		}

		// Ask the validator before asking the officer. It binds its own submit
		// handler only once its modules have finished loading, which can be after
		// this one, so its verdict is not reliably readable off the event - and a
		// dialog in front of a form that is about to be rejected gets answered for
		// nothing.
		if (typeof $.fn.isValid === 'function' && $('#form').isValid(null, null, false) === false)
		{
			return;
		}

		event.preventDefault();

		$.getJSON(phpGWLink('index.php', {menuaction: 'booking.uiallocation.cascade_preview', id: reservation_id}, true))
			.done(function (preview)
			{
				if (!preview || !preview.grouped)
				{
					allocationSubmitWithScope(null, false);
					return;
				}

				allocationAskScope(preview);
			})
			.fail(function ()
			{
				// No counts to ask with. Save the way this form saved before the
				// dialogs existed rather than standing between the officer and his
				// edit.
				allocationSubmitWithScope(null, false);
			});
	});
});
