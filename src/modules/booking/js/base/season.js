var building_id_selection = "";
var regulations_select_all = "";

// Tab functionality for season pages
function set_tab(tab)
{
	$("#tab").val(tab);
}

$(document).ready(function ()
{
	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'booking.uipermission_root.index_accounts'}, true),
		'field_officer_name', 'field_officer_id', 'officer_container');
	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'booking.uibuilding.index'}, true),
		'field_building_name', 'field_building_id', 'building_container');		
});

$(window).on('load', function()
{
	building_id = $('#field_building_id').val();
	if (building_id)
	{
		populateTableChkResources(building_id, initialSelection);
		building_id_selection = building_id;
	}
	$("#field_building_name").on("autocompleteselect", function (event, ui)
	{
		var building_id = ui.item.value;
		var selection = [];
		if (building_id != building_id_selection)
		{
			populateTableChkResources(building_id, selection);
			building_id_selection = building_id;
		}
	});
});

if ($.formUtils)
{
	$.formUtils.addValidator({
		name: 'application_resources',
		validatorFunction: function (value, $el, config, language, $form)
		{
			var n = 0;
			$('#resources-container table input[name="resources[]"]').each(function ()
			{
				if ($(this).is(':checked'))
				{
					n++;
				}
			});
			var v = (n > 0) ? true : false;
			return v;
		},
		errorMessage: 'Please choose at least 1 resource',
		errorMessageKey: 'application_resources'
	});
}

function populateTableChkResources(building_id, selection)
{
	var url = phpGWLink('index.php', {menuaction: 'booking.uiresource.index', sort: 'name', filter_building_id: building_id, length: -1}, true);
	var container = 'resources-container';
	var colDefsResources = [{label: '', object: [{type: 'input', attrs: [
						{name: 'type', value: 'checkbox'}, {name: 'name', value: 'resources[]'}
					]}
			], value: 'id', checked: selection}, {key: 'name', label: lang['Name']}, {key: 'rescategory_name', label: lang['Resource Type']}
	];
	populateTableChk(url, container, colDefsResources);
}

function populateTableChk(url, container, colDefs)
{
	createTable(container, url, colDefs, '', 'pure-table pure-table-bordered');
}

function copy_season(id)
{
	r = confirm("kopiere sesong inkludert ukeplan, rammetider og rettigheter?");
	if (r == true)
	{
		var requesturl = phpGWLink('index.php', {menuaction: 'booking.uiseason.copy_season', 'id': id});
		window.location.href = requesturl;
	}
}

// Season boundaries functionality
$(document).ready(function() {
	// Wait a bit more to ensure all elements are loaded
	setTimeout(function() {
		// Add quick select buttons for checkbox day selection
		var $weekdayCheckboxes = $('.weekday-checkboxes');
		console.log('Found weekday checkboxes:', $weekdayCheckboxes.length);
		
		if ($weekdayCheckboxes.length > 0) {
			var $quickButtons = $('<div class="boundary-quick-select" style="margin-top: 10px;"></div>');
			$quickButtons.append('<button type="button" class="pure-button pure-button-small" data-action="weekdays">Weekdays</button> ');
			$quickButtons.append('<button type="button" class="pure-button pure-button-small" data-action="weekend">Weekend</button> ');
			$quickButtons.append('<button type="button" class="pure-button pure-button-small" data-action="all">All</button> ');
			$quickButtons.append('<button type="button" class="pure-button pure-button-small" data-action="clear">Clear</button>');
			
			$weekdayCheckboxes.after($quickButtons);
			
			// Handle checkbox styling and interactions
			function updateCheckboxStates() {
				$('.weekday-checkbox').each(function() {
					var $label = $(this);
					var $checkbox = $label.find('input[type="checkbox"]');
					if ($checkbox.prop('checked')) {
						$label.addClass('checked');
					} else {
						$label.removeClass('checked');
					}
				});
			}
			
			// Initialize checkbox states on page load
			updateCheckboxStates();
			
			// Handle checkbox clicks
			$(document).on('click', '.weekday-checkbox', function(e) {
				e.preventDefault();
				console.log('Weekday checkbox clicked');
				var $checkbox = $(this).find('input[type="checkbox"]');
				$checkbox.prop('checked', !$checkbox.prop('checked'));
				console.log('Checkbox state:', $checkbox.prop('checked'));
				updateCheckboxStates();
			});
			
			// Also handle direct checkbox clicks (in case they become visible)
			$('input[name="wday[]"]').on('change', function() {
				updateCheckboxStates();
			});
			
			// Handle quick select buttons
			$quickButtons.on('click', 'button', function(e) {
				e.preventDefault();
				var action = $(this).data('action');
				var $checkboxes = $('input[name="wday[]"]');
				console.log('Quick button clicked:', action);
				
				// Clear all first
				$checkboxes.prop('checked', false);
				
				switch(action) {
					case 'weekdays':
						$('input[name="wday[]"][value="1"], input[name="wday[]"][value="2"], input[name="wday[]"][value="3"], input[name="wday[]"][value="4"], input[name="wday[]"][value="5"]').prop('checked', true);
						break;
					case 'weekend':
						$('input[name="wday[]"][value="6"], input[name="wday[]"][value="7"]').prop('checked', true);
						break;
					case 'all':
						$checkboxes.prop('checked', true);
						break;
					case 'clear':
						// Already cleared above
						break;
				}
				updateCheckboxStates();
			});
		}
	}, 100);  // Wait 100ms for DOM to be fully ready
	
	// Form validation for boundaries
	$('form[name="form"]').on('submit', function(e) {
		var $checkedDays = $('input[name="wday[]"]:checked');
		if ($checkedDays.length === 0) {
			alert('Please select at least one day of the week.');
			e.preventDefault();
			return false;
		}
		
		var fromTime = $('input[name="from_"]').val();
		var toTime = $('input[name="to_"]').val();
		
		if (!fromTime || !toTime) {
			alert('Please fill in both from and to times.');
			e.preventDefault();
			return false;
		}
		
		// Simple time validation (HH:MM format)
		if (!/^\d{2}:\d{2}$/.test(fromTime) || !/^\d{2}:\d{2}$/.test(toTime)) {
			alert('Please enter times in HH:MM format.');
			e.preventDefault();
			return false;
		}
		
		if (fromTime >= toTime) {
			alert('From time must be earlier than to time.');
			e.preventDefault();
			return false;
		}
	});
});
