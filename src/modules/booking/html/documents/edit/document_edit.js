(function () {
	'use strict';

	var OWNER_ID = window.__documentEdit.ownerId;
	var DOC_ID = window.__documentEdit.documentId;
	var DOWNLOAD_URL = '/booking/buildings/documents/' + DOC_ID + '/download';
	var LANG = window.__documentEdit.lang;

	// Dynamic API base — updates when building changes
	var currentOwnerId = OWNER_ID;

	function getApiBase() {
		return '/booking/buildings/' + currentOwnerId + '/documents/' + DOC_ID;
	}

	// State
	var focalX = null;
	var focalY = null;
	var rotation = 0;
	var savedRotation = 0;
	var focalPointPicker = null;
	var alertTimer = null;
	var buildingSelector = null;

	// DOM refs
	var form = document.getElementById('document-form');
	var fieldName = document.getElementById('field-name');
	var fieldDesc = document.getElementById('field-description');
	var fieldCat = document.getElementById('field-category');
	var alertSuccess = document.getElementById('alert-success');
	var alertError = document.getElementById('alert-error');
	var focalSection = document.getElementById('focal-section');
	var focalInfoText = document.getElementById('focal-info-text');
	var btnSave = document.getElementById('btn-save');
	var btnDelete = document.getElementById('btn-delete');

	// -- Building Selector --

	var buildingContainer = document.getElementById('building-select');
	if (buildingContainer) {
		buildingSelector = new BuildingSelect(buildingContainer, {
			apiUrl: '/booking/buildings',
			value: OWNER_ID,
			displayValue: window.__documentEdit.buildingName || '',
			onChange: function (id) {
				currentOwnerId = id;
			}
		});
	}

	// -- Alerts --

	function showAlert(el, msg) {
		if (alertTimer) clearTimeout(alertTimer);
		hideAlerts();
		el.textContent = msg;
		el.classList.remove('is-hidden', 'is-fading');
		alertTimer = setTimeout(function () {
			el.classList.add('is-fading');
			setTimeout(function () { el.classList.add('is-hidden'); }, 300);
		}, 4000);
	}

	function hideAlerts() {
		alertSuccess.classList.add('is-hidden');
		alertSuccess.classList.remove('is-fading');
		alertError.classList.add('is-hidden');
		alertError.classList.remove('is-fading');
	}

	// -- Save button state --

	function setSaving(isSaving) {
		btnSave.disabled = isSaving;
		btnSave.textContent = isSaving ? LANG.saving : LANG.update;
	}

	// -- Focal info display (in main form) --

	function updateFocalInfo() {
		if (focalX !== null && focalY !== null) {
			var text = focalX.toFixed(1) + '%, ' + focalY.toFixed(1) + '%';
			if (rotation) text += ', ' + rotation + '\u00B0';
			focalInfoText.textContent = '(' + text + ')';
		} else {
			focalInfoText.textContent = '';
		}
	}

	// -- Preview images --

	function updatePreviews() {
		var rotationDiff = (rotation - savedRotation + 360) % 360;
		var src = DOWNLOAD_URL + (rotationDiff ? '?rotation=' + rotationDiff : '');
		var pos = (focalX !== null) ? focalX + '% ' + focalY + '%' : '50% 50%';
		['preview-card', 'preview-square', 'preview-portrait'].forEach(function (id) {
			var img = document.getElementById(id);
			img.src = src;
			img.style.objectPosition = pos;
		});
	}

	// -- Categories --

	function loadCategories() {
		return fetch('/booking/buildings/documents/categories', { credentials: 'same-origin' })
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (categories) {
				fieldCat.innerHTML = '';
				var placeholder = document.createElement('option');
				placeholder.value = '';
				placeholder.textContent = LANG.selectCategory;
				fieldCat.appendChild(placeholder);

				categories.forEach(function (cat) {
					var opt = document.createElement('option');
					opt.value = cat.value;
					opt.textContent = cat.label;
					fieldCat.appendChild(opt);
				});
			});
	}

	// -- Populate form from API response --

	function populateForm(doc) {
		fieldName.value = doc.name || '';
		fieldDesc.value = doc.description || '';
		fieldCat.value = doc.category || '';

		focalX = (doc.focal_point_x !== null && doc.focal_point_x !== undefined)
			? parseFloat(doc.focal_point_x) : null;
		focalY = (doc.focal_point_y !== null && doc.focal_point_y !== undefined)
			? parseFloat(doc.focal_point_y) : null;
		rotation = parseInt(doc.rotation, 10) || 0;
		savedRotation = rotation;

		var ext = (doc.name || '').split('.').pop().toLowerCase();
		var imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif'];

		if (imageExts.indexOf(ext) !== -1) {
			focalSection.style.display = '';
			updateFocalInfo();
			updatePreviews();
		} else {
			focalSection.style.display = 'none';
		}
	}

	// -- API calls --

	function loadDocument() {
		fetch(getApiBase(), { credentials: 'same-origin' })
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(populateForm)
			.catch(function (err) {
				showAlert(alertError, 'Failed to load document: ' + err.message);
			});
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		hideAlerts();
		setSaving(true);

		var body = {
			description: fieldDesc.value,
			category: fieldCat.value
		};

		// Include owner_id if building changed
		if (currentOwnerId !== OWNER_ID) {
			body.owner_id = currentOwnerId;
		}

		if (focalSection.style.display !== 'none') {
			body.focal_point_x = focalX;
			body.focal_point_y = focalY;
			body.rotation = rotation;
		}

		// Always use the original owner_id for the PATCH request URL
		var patchUrl = '/booking/buildings/' + OWNER_ID + '/documents/' + DOC_ID;

		fetch(patchUrl, {
			method: 'PATCH',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		})
			.then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'Update failed'); });
				return r.json();
			})
			.then(function (doc) {
				// If building changed, update the base owner ID for future requests
				if (doc.owner_id) {
					OWNER_ID = doc.owner_id;
					currentOwnerId = doc.owner_id;
				}
				populateForm(doc);
				showAlert(alertSuccess, LANG.updated);
			})
			.catch(function (err) {
				showAlert(alertError, err.message);
			})
			.finally(function () {
				setSaving(false);
			});
	});

	btnDelete.addEventListener('click', function () {
		if (!confirm(LANG.confirmDelete)) return;
		btnDelete.disabled = true;

		fetch(getApiBase(), {
			method: 'DELETE',
			credentials: 'same-origin'
		})
			.then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'Delete failed'); });
				history.back();
			})
			.catch(function (err) {
				showAlert(alertError, err.message);
				btnDelete.disabled = false;
			});
	});

	// -- Focal point modal --

	var focalModal = document.getElementById('focal-modal');
	var focalImage = document.getElementById('focal-point-image');
	var currentRotation = 0;

	function openFocalModal() {
		currentRotation = rotation;
		var rotationDiff = (currentRotation - savedRotation + 360) % 360;
		var imageUrl = DOWNLOAD_URL + (rotationDiff ? '?rotation=' + rotationDiff : '');

		focalImage.src = imageUrl;

		focalImage.onload = function () {
			if (focalPointPicker) {
				focalPointPicker.dispose();
			}
			var initialPoint = {
				x: (focalX !== null ? focalX : 50) / 100,
				y: (focalY !== null ? focalY : 50) / 100
			};
			focalPointPicker = new FocalPointPicker(
				focalImage,
				updateModalFocalDisplay,
				initialPoint
			);
			updateModalFocalDisplay(initialPoint);
			updateRotationDisplay();
		};

		focalModal.classList.add('show');
	}

	function closeFocalModal() {
		focalModal.classList.remove('show');
	}

	function updateModalFocalDisplay(point) {
		var xPct = Math.round(point.x * 100);
		var yPct = Math.round(point.y * 100);
		document.getElementById('focal-display').textContent =
			'X: ' + xPct + '%, Y: ' + yPct + '%';
	}

	function updateRotationDisplay() {
		document.getElementById('rotation-display').textContent =
			'Rotation: ' + currentRotation + '\u00B0';
	}

	function reloadFocalImage() {
		var currentFocalPoint = focalPointPicker ? focalPointPicker.getFocalPoint() : { x: 0.5, y: 0.5 };
		if (focalPointPicker) {
			focalPointPicker.dispose();
			focalPointPicker = null;
		}
		updateRotationDisplay();

		var rotationDiff = (currentRotation - savedRotation + 360) % 360;
		var imageUrl = DOWNLOAD_URL + (rotationDiff ? '?rotation=' + rotationDiff : '')
			+ (rotationDiff ? '&' : '?') + '_cb=' + Date.now();

		focalImage.onload = function () {
			focalPointPicker = new FocalPointPicker(
				focalImage,
				updateModalFocalDisplay,
				currentFocalPoint
			);
			updateModalFocalDisplay(currentFocalPoint);
		};
		focalImage.src = imageUrl;
	}

	// Event listeners
	document.getElementById('btn-edit-focal').addEventListener('click', openFocalModal);
	document.getElementById('focal-cancel').addEventListener('click', closeFocalModal);
	document.getElementById('focal-cancel-x').addEventListener('click', closeFocalModal);
	focalModal.addEventListener('click', function (e) {
		if (e.target === focalModal) closeFocalModal();
	});

	document.getElementById('rotate-left').addEventListener('click', function () {
		currentRotation = (currentRotation - 90 + 360) % 360;
		reloadFocalImage();
	});

	document.getElementById('rotate-right').addEventListener('click', function () {
		currentRotation = (currentRotation + 90) % 360;
		reloadFocalImage();
	});

	document.getElementById('reset-rotation').addEventListener('click', function () {
		currentRotation = 0;
		reloadFocalImage();
	});

	document.getElementById('focal-reset').addEventListener('click', function () {
		if (focalPointPicker) {
			focalPointPicker.setFocalPoint(getCenterFocalPoint());
		}
	});

	document.getElementById('focal-remove').addEventListener('click', function () {
		focalX = null;
		focalY = null;
		rotation = 0;
		updateFocalInfo();
		updatePreviews();
		closeFocalModal();
	});

	document.getElementById('focal-apply').addEventListener('click', function () {
		if (focalPointPicker) {
			var point = focalPointPicker.getFocalPoint();
			focalX = Math.round(point.x * 1000) / 10;
			focalY = Math.round(point.y * 1000) / 10;
		}
		rotation = currentRotation;
		updateFocalInfo();
		updatePreviews();
		closeFocalModal();
	});

	// Boot — load categories first so <select> is populated before setting value
	loadCategories()
		.then(loadDocument)
		.catch(function () { loadDocument(); });
})();
