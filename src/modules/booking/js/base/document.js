var ownerType = "";
var focalPointPicker = null;

$(document).ready(function ()
{
	var ownerType = documentOwnerType;
	if (documentOwnerAutocomplete)
	{
		label_attr = ownerType == 'resource' ? 'full_name' : 'name';
		JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction: 'booking.ui' + ownerType + '.index'}, true),
			'field_owner_name', 'field_owner_id', 'owner_container', label_attr);
	}

	// Focal point editor
	$('#edit-focal-point-btn').on('click', function(e) {
		e.preventDefault();
		openFocalPointEditor();
	});

	$('#save-focal-point-btn').on('click', function() {
		saveFocalPoint();
	});

	$('#reset-focal-point-btn').on('click', function() {
		if (focalPointPicker) {
			focalPointPicker.setFocalPoint(getCenterFocalPoint());
		}
	});

	$('#remove-focal-point-btn').on('click', function() {
		removeFocalPoint();
	});
});

function openFocalPointEditor() {
	var downloadLink = $('#focal-point-download-link').val();
	var currentX = parseFloat($('#field_focal_point_x').val()) || 50;
	var currentY = parseFloat($('#field_focal_point_y').val()) || 50;

	// Set the image source
	$('#focal-point-image').attr('src', downloadLink);

	// Wait for image to load
	$('#focal-point-image').on('load', function() {
		// Initialize picker with current focal point
		var initialPoint = {
			x: currentX / 100,
			y: currentY / 100
		};

		if (focalPointPicker) {
			focalPointPicker.dispose();
		}

		focalPointPicker = new FocalPointPicker(
			document.getElementById('focal-point-image'),
			updateFocalPointDisplay,
			initialPoint
		);

		updateFocalPointDisplay(initialPoint);
	});

	// Show modal
	$('#focal-point-modal').modal('show');
}

function updateFocalPointDisplay(point) {
	var xPercent = Math.round(point.x * 100);
	var yPercent = Math.round(point.y * 100);
	$('#focal-point-display').text('X: ' + xPercent + '%, Y: ' + yPercent + '%');
}

function saveFocalPoint() {
	if (focalPointPicker) {
		var point = focalPointPicker.getFocalPoint();
		var xPercent = Math.round(point.x * 100);
		var yPercent = Math.round(point.y * 100);


		$('#field_focal_point_x').val(xPercent);
		$('#field_focal_point_y').val(yPercent);


		// Update preview images to show new focal point
		$('.preview-image').each(function() {
			$(this).css('object-position', xPercent + '% ' + yPercent + '%');
		});

		// Update preview labels
		$('.focal-label').text(' (focal: ' + xPercent + '%, ' + yPercent + '%)');

		// Update the button text
		$('#edit-focal-point-btn').text(lang_edit_focal_point + ' (' + xPercent + '%, ' + yPercent + '%)');

		$('#focal-point-modal').modal('hide');

	} else {
	}
}

function removeFocalPoint() {
	$('#field_focal_point_x').val('');
	$('#field_focal_point_y').val('');

	// Reset preview images to default center
	$('.preview-image').css('object-position', '50% 50%');
	$('.focal-label').text('');

	$('#focal-point-modal').modal('hide');
}


