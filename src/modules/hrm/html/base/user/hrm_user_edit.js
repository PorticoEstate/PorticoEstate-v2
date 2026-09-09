(function ()
{
	'use strict';

	var root = document.getElementById('hrm-training-form');
	var placeSelect = document.getElementById('hrm-training-place');
	var newPlace = document.getElementById('hrm-training-new-place');
	if (!root || !placeSelect || !newPlace) return;

	function toJqueryDateFormat(phpFormat)
	{
		return String(phpFormat || 'Y-m-d')
			.replace(/Y/g, 'yy')
			.replace(/y/g, 'y')
			.replace(/m/g, 'mm')
			.replace(/n/g, 'm')
			.replace(/d/g, 'dd')
			.replace(/j/g, 'd');
	}

	function syncNewPlace()
	{
		newPlace.hidden = placeSelect.value !== 'new_place';
	}

	if (typeof window.jQuery !== 'undefined' && window.jQuery.fn.datepicker)
	{
		window.jQuery('#hrm-training-start-date, #hrm-training-end-date').datepicker({
			dateFormat: toJqueryDateFormat(root.dataset.dateFormat || 'Y-m-d'),
			changeMonth: true,
			changeYear: true
		});
	}

	placeSelect.addEventListener('change', syncNewPlace);
	syncNewPlace();
})();