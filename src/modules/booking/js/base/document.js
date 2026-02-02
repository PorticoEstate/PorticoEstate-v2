var ownerType = "";
var focalPointPicker = null;
var currentRotation = 0;
var savedRotation = 0;

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

	$('#rotate-left-btn').on('click', function() {
		rotateImage(-90);
	});

	$('#rotate-right-btn').on('click', function() {
		rotateImage(90);
	});

	$('#reset-rotation-btn').on('click', function() {
		currentRotation = 0; // Reset to 0 degrees (original orientation)
		updateImageRotation();
	});
});

function openFocalPointEditor() {
	var downloadLink = $('#focal-point-download-link').val();
	var currentX = parseFloat($('#field_focal_point_x').val()) || 50;
	var currentY = parseFloat($('#field_focal_point_y').val()) || 50;
	currentRotation = parseInt($('#field_rotation').val()) || 0;
	savedRotation = currentRotation; // Track the rotation that's already applied to the file

	// Load image without rotation parameter - it's already physically rotated
	// No need to apply additional rotation on initial load
	var imageUrl = downloadLink;

	// Set the image source
	$('#focal-point-image').attr('src', imageUrl);

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

		var image = document.getElementById('focal-point-image');

		focalPointPicker = new FocalPointPicker(
			image,
			updateFocalPointDisplay,
			initialPoint
		);

		// Store rotation in picker (for tracking purposes only)
		focalPointPicker.rotation = currentRotation;

		updateFocalPointDisplay(initialPoint);
		$('#rotation-display').text('Rotation: ' + currentRotation + '°');
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
		$('#field_rotation').val(currentRotation);


		// Update preview images to show new focal point and rotation
		var baseUrl = $('#focal-point-download-link').val().split('?')[0];

		// Calculate rotation difference from saved state for preview
		var rotationDiff = (currentRotation - savedRotation + 360) % 360;
		var imageUrl = rotationDiff ? baseUrl + '?rotation=' + rotationDiff : baseUrl;

		$('.preview-image').each(function() {
			$(this).attr('src', imageUrl);
			$(this).css('object-position', xPercent + '% ' + yPercent + '%');
		});

		// Update preview labels
		var labelText = ' (focal: ' + xPercent + '%, ' + yPercent + '%';
		if (currentRotation) {
			labelText += ', rotation: ' + currentRotation + '°';
		}
		labelText += ')';
		$('.focal-label').text(labelText);

		// Update the button text
		var buttonText = lang_edit_focal_point + ' (' + xPercent + '%, ' + yPercent + '%';
		if (currentRotation) {
			buttonText += ', ' + currentRotation + '°';
		}
		buttonText += ')';
		$('#edit-focal-point-btn').text(buttonText);

		$('#focal-point-modal').modal('hide');

	} else {
	}
}

function removeFocalPoint() {
	$('#field_focal_point_x').val('');
	$('#field_focal_point_y').val('');
	$('#field_rotation').val(0);

	// Reset preview images to default center and original orientation
	// Show what rotation=0 looks like (rotate back by -savedRotation degrees)
	var baseUrl = $('#focal-point-download-link').val().split('?')[0];
	var rotationDiff = (0 - savedRotation + 360) % 360;
	var imageUrl = rotationDiff ? baseUrl + '?rotation=' + rotationDiff : baseUrl;

	$('.preview-image').each(function() {
		$(this).attr('src', imageUrl);
		$(this).css('object-position', '50% 50%');
	});
	$('.focal-label').text('');

	$('#focal-point-modal').modal('hide');
}

function rotateImage(degrees) {
	currentRotation = (currentRotation + degrees) % 360;
	if (currentRotation < 0) {
		currentRotation += 360;
	}
	updateImageRotation();
}

function updateImageRotation() {
	var image = document.getElementById('focal-point-image');
	if (!image) return;

	// Get current focal point before disposing picker
	var currentFocalPoint = focalPointPicker ? focalPointPicker.getFocalPoint() : { x: 0.5, y: 0.5 };

	// Dispose old picker if it exists
	if (focalPointPicker) {
		focalPointPicker.dispose();
		focalPointPicker = null;
	}

	// Update rotation display immediately
	$('#rotation-display').text('Rotation: ' + currentRotation + '°');

	// Reload image with rotation parameter to get server-side rotated version
	var baseUrl = $('#focal-point-download-link').val().split('?')[0];

	// Calculate rotation to apply: difference from saved state
	var rotationDiff = (currentRotation - savedRotation + 360) % 360;

	// Add cache busting parameter to force reload
	var imageUrl = rotationDiff ? baseUrl + '?rotation=' + rotationDiff + '&_cb=' + new Date().getTime() : baseUrl + '?_cb=' + new Date().getTime();

	// Remove old load handler if any
	$(image).off('load');

	image.src = imageUrl;

	// Wait for image to reload with new rotation
	$(image).one('load', function() {
		// Recreate the focal point picker with the server-rotated image
		focalPointPicker = new FocalPointPicker(
			image,
			updateFocalPointDisplay,
			currentFocalPoint
		);

		// Store rotation in picker
		focalPointPicker.rotation = currentRotation;

		updateFocalPointDisplay(currentFocalPoint);
	});
}


