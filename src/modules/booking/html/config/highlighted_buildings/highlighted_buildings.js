(function () {
	'use strict';

	var CFG = window.__highlightedBuildings;
	var L = CFG.lang;
	var MAX = CFG.maxBuildings;

	var buildings = []; // [{id, name}, ...]

	var selectorContainer = document.getElementById('building-search');
	var listEl = document.getElementById('building-list');
	var saveBtn = document.getElementById('save-btn');
	var maxWarning = document.getElementById('max-warning');
	var alertContainer = document.getElementById('alert-container');

	// --- Init BuildingSelect component ---

	var selector = new BuildingSelect(selectorContainer, {
		apiUrl: '/booking/buildings',
		onChange: function (id, name) {
			addBuilding({ id: id, name: name });
			// Clear the selector after adding
			selector.setValue(null, '');
		}
	});

	saveBtn.addEventListener('click', save);

	// Load existing config
	loadConfig();

	// --- Config loading ---

	function loadConfig() {
		fetch('/booking/config/bookingfrontend?keys=highlighted_buildings', {
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				var raw = data.highlighted_buildings || '';
				var ids = raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
				if (!ids.length) {
					renderList();
					return;
				}
				resolveBuildings(ids);
			})
			.catch(function () {
				renderList();
			});
	}

	function resolveBuildings(ids) {
		fetch('/booking/buildings', { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (allBuildings) {
				var map = {};
				allBuildings.forEach(function (b) { map[b.id] = b.name; });
				buildings = [];
				ids.forEach(function (id) {
					var numId = parseInt(id, 10);
					if (map[numId]) {
						buildings.push({ id: numId, name: map[numId] });
					}
				});
				renderList();
			})
			.catch(function () {
				renderList();
			});
	}

	// --- List management ---

	function addBuilding(b) {
		if (buildings.length >= MAX) {
			showMaxWarning();
			return;
		}
		if (buildings.some(function (x) { return x.id === b.id; })) {
			return;
		}
		buildings.push({ id: b.id, name: b.name });
		renderList();
	}

	function removeBuilding(index) {
		buildings.splice(index, 1);
		hideMaxWarning();
		renderList();
	}

	function moveBuilding(index, direction) {
		var newIndex = index + direction;
		if (newIndex < 0 || newIndex >= buildings.length) return;
		var temp = buildings[index];
		buildings[index] = buildings[newIndex];
		buildings[newIndex] = temp;
		renderList();
	}

	function renderList() {
		listEl.innerHTML = '';

		if (buildings.length >= MAX) {
			showMaxWarning();
		} else {
			hideMaxWarning();
		}

		buildings.forEach(function (b, i) {
			var row = document.createElement('div');
			row.className = 'highlighted-buildings__item';

			var num = document.createElement('span');
			num.className = 'highlighted-buildings__item-number';
			num.textContent = (i + 1) + '.';

			var name = document.createElement('span');
			name.className = 'highlighted-buildings__item-name';
			name.textContent = b.name;

			row.appendChild(num);
			row.appendChild(name);

			if (i > 0) {
				var upBtn = document.createElement('button');
				upBtn.type = 'button';
				upBtn.className = 'app-button app-button-secondary app-button-sm';
				upBtn.textContent = '\u2191';
				upBtn.title = L.move_up;
				upBtn.addEventListener('click', function () { moveBuilding(i, -1); });
				row.appendChild(upBtn);
			}

			if (i < buildings.length - 1) {
				var downBtn = document.createElement('button');
				downBtn.type = 'button';
				downBtn.className = 'app-button app-button-secondary app-button-sm';
				downBtn.textContent = '\u2193';
				downBtn.title = L.move_down;
				downBtn.addEventListener('click', function () { moveBuilding(i, 1); });
				row.appendChild(downBtn);
			}

			var removeBtn = document.createElement('button');
			removeBtn.type = 'button';
			removeBtn.className = 'app-button app-button-danger app-button-sm';
			removeBtn.textContent = '\u00d7';
			removeBtn.title = L.remove;
			removeBtn.addEventListener('click', function () { removeBuilding(i); });
			row.appendChild(removeBtn);

			listEl.appendChild(row);
		});
	}

	function showMaxWarning() {
		maxWarning.textContent = L.max_buildings_reached;
		maxWarning.hidden = false;
	}

	function hideMaxWarning() {
		maxWarning.hidden = true;
	}

	// --- Save ---

	function save() {
		var ids = buildings.map(function (b) { return b.id; }).join(',');

		saveBtn.disabled = true;

		fetch('/booking/config/bookingfrontend', {
			method: 'PUT',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ highlighted_buildings: ids })
		})
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function () {
				showAlert(L.changes_saved, 'success');
			})
			.catch(function () {
				showAlert(L.save_error, 'danger');
			})
			.finally(function () {
				saveBtn.disabled = false;
			});
	}

	// --- Alerts ---

	function showAlert(message, type) {
		var div = document.createElement('div');
		div.className = 'app-alert app-alert-' + type;
		div.textContent = message;
		alertContainer.innerHTML = '';
		alertContainer.appendChild(div);
		setTimeout(function () {
			if (div.parentNode) div.remove();
		}, 4000);
	}
})();
