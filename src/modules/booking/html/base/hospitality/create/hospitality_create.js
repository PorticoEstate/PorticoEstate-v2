(function () {
	'use strict';

	var CFG = window.__hospitalityCreate;
	var L = CFG.lang;

	var form = document.getElementById('hospitality-form');
	var nameInput = document.getElementById('field-name');
	var descInput = document.getElementById('field-description');
	var resourceSelect = document.getElementById('field-resource');
	var btnCreate = document.getElementById('btn-create');
	var alertSuccess = document.getElementById('alert-success');
	var alertError = document.getElementById('alert-error');

	var buildingContainer = document.getElementById('building-select');

	// Init building selector
	var buildingSelect = new BuildingSelect(buildingContainer, {
		apiUrl: CFG.buildingsUrl,
		onChange: function (buildingId) {
			loadResources(buildingId);
		}
	});

	function loadResources(buildingId) {
		resourceSelect.innerHTML = '<option value="">' + L.loading + '...</option>';
		resourceSelect.disabled = true;
		btnCreate.disabled = true;

		fetch(CFG.buildingsUrl + '/' + buildingId + '/resources', { credentials: 'same-origin' })
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (resources) {
				resourceSelect.innerHTML = '<option value="">' + L.select_resource + '</option>';
				resources.forEach(function (res) {
					var opt = document.createElement('option');
					opt.value = res.id;
					opt.textContent = res.name;
					resourceSelect.appendChild(opt);
				});
				resourceSelect.disabled = false;
				updateCreateButton();
			})
			.catch(function (err) {
				console.error('Failed to load resources', err);
				resourceSelect.innerHTML = '<option value="">' + L.error + '</option>';
			});
	}

	function updateCreateButton() {
		btnCreate.disabled = !nameInput.value.trim() || !resourceSelect.value;
	}

	nameInput.addEventListener('input', updateCreateButton);
	resourceSelect.addEventListener('change', updateCreateButton);

	function showAlert(el, msg) {
		el.textContent = msg;
		el.classList.remove('is-hidden');
	}

	function hideAlerts() {
		alertSuccess.classList.add('is-hidden');
		alertError.classList.add('is-hidden');
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		hideAlerts();

		var name = nameInput.value.trim();
		var resourceId = parseInt(resourceSelect.value, 10);

		if (!name) {
			showAlert(alertError, L.name);
			nameInput.focus();
			return;
		}
		if (!resourceId) {
			showAlert(alertError, L.select_resource);
			resourceSelect.focus();
			return;
		}

		btnCreate.disabled = true;

		var payload = {
			name: name,
			description: descInput.value.trim(),
			resource_id: resourceId,
			remote_serving_enabled: 1,
			allow_on_site_hospitality: 0,
			order_by_time_value: 24,
			order_by_time_unit: 'hours'
		};

		fetch(CFG.apiUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		})
			.then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'HTTP ' + r.status); });
				return r.json();
			})
			.then(function (result) {
				window.location.href = CFG.showBaseUrl + result.id;
			})
			.catch(function (err) {
				showAlert(alertError, L.error + ': ' + err.message);
				btnCreate.disabled = false;
			});
	});
})();
