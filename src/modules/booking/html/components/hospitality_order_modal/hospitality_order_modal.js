/**
 * Reusable Hospitality Order Creation Modal
 *
 * Works in two contexts:
 *   1. Hospitality page: hospitalityId known, user selects application
 *   2. Application page: applicationId known, user selects hospitality
 *
 * Usage:
 *   HospitalityOrderModal.open({
 *       // Context — provide one or both:
 *       hospitalityId: 4,              // fixed (hospitality page)
 *       applicationId: 123,            // fixed (application page)
 *
 *       // When hospitalityId is NOT fixed, provide list for selector:
 *       hospitalities: [{id:4, name:'Kitchen', resource_id:1}, ...],
 *
 *       // URLs
 *       ordersStoreUrl: '/booking/hospitality-orders',
 *       applicationsBaseUrl: '/booking/applications',
 *       // One of these depending on context:
 *       relevantAppsUrl: '/booking/hospitality/4/relevant-applications',
 *       deliveryLocationsUrl: '/booking/hospitality/4/delivery-locations',
 *       deliveryLocationsBaseUrl: '/booking/hospitality',  // + /{id}/delivery-locations
 *
 *       // For date filtering (hospitality page knows this, app page gets it per-hospitality)
 *       hospMainResourceId: 1,
 *
 *       // Helpers (from host page)
 *       lang: function(key) {},
 *       esc: function(str) {},
 *       fetchJson: function(url) {},
 *       postJson: function(url, data) {},
 *       showModal: function(id, title, body, footer) {},
 *       closeModal: function(id) {},
 *       showToast: function(msg, type) {},
 *       onSuccess: function() {},      // called after successful creation
 *   });
 */
var HospitalityOrderModal = (function () {
	'use strict';

	function open(opts) {
		var lang = opts.lang;
		var esc = opts.esc;
		var fetchJson = opts.fetchJson;
		var postJson = opts.postJson;

		var fixedHospitalityId = opts.hospitalityId || null;
		var fixedApplicationId = opts.applicationId || null;
		var hospitalities = opts.hospitalities || [];
		var hospMainResourceId = opts.hospMainResourceId || null;

		var ordersStoreUrl = opts.ordersStoreUrl;
		var applicationsBaseUrl = opts.applicationsBaseUrl;
		var relevantAppsUrl = opts.relevantAppsUrl || null;
		var deliveryLocationsUrl = opts.deliveryLocationsUrl || null;
		var deliveryLocationsBaseUrl = opts.deliveryLocationsBaseUrl || null;

		// Track the currently selected hospitality (for app-page context)
		var _selectedHospitalityId = fixedHospitalityId;

		var body = '';

		// ── Hospitality selector (only when not fixed) ──
		if (!fixedHospitalityId) {
			body += '<label class="app-show__modal-label">' + esc(lang('hospitality')) + ' *</label>' +
				'<div id="modal-order-hosp-container" class="search-select">' +
				'<input type="text" class="search-select__input app-show__modal-textarea" style="min-height:auto;height:2.25rem" placeholder="' + esc(lang('hospitality')) + '..." autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
				'<input type="hidden" id="modal-order-hosp-value">' +
				'<ul class="search-select__dropdown" role="listbox"></ul>' +
				'</div>';
		}

		// ── Application selector (only when not fixed) ──
		if (!fixedApplicationId) {
			body += '<label class="app-show__modal-label"' + (!fixedHospitalityId ? ' style="margin-top:0.75rem"' : '') + '>' + esc(lang('application')) + ' *</label>' +
				'<div id="modal-order-app-container" class="search-select">' +
				'<input type="text" class="search-select__input app-show__modal-textarea" style="min-height:auto;height:2.25rem" placeholder="#ID..." autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
				'<input type="hidden" id="modal-order-app-value">' +
				'<ul class="search-select__dropdown" role="listbox"></ul>' +
				'</div>';
		}

		// ── Location selector ──
		body += '<label class="app-show__modal-label" style="margin-top:0.75rem">' + esc(lang('location')) + ' *</label>' +
			'<div id="modal-order-loc-container" class="search-select">' +
			'<input type="text" class="search-select__input app-show__modal-textarea" style="min-height:auto;height:2.25rem" placeholder="' + esc(lang('location')) + '..." autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
			'<input type="hidden" id="modal-order-loc-value">' +
			'<ul class="search-select__dropdown" role="listbox"></ul>' +
			'</div>';

		// ── Serving time ──
		body += '<label class="app-show__modal-label" style="margin-top:0.75rem">' + esc(lang('servingTime')) + ' *</label>' +
			'<div id="modal-order-serving-container" style="display:flex;gap:0.5rem">' +
			'<select id="modal-order-serving-date" class="app-show__modal-textarea" style="min-height:auto;height:2.25rem;flex:1;max-width:10rem" disabled>' +
			'<option value="">' + esc(lang('selectDate')) + '</option></select>' +
			'<select id="modal-order-serving-time" class="app-show__modal-textarea" style="min-height:auto;height:2.25rem;flex:1;max-width:10rem" disabled>' +
			'<option value="">' + esc(lang('selectTime')) + '</option></select>' +
			'</div>';

		// ── Comment ──
		body += '<label class="app-show__modal-label" for="modal-order-comment" style="margin-top:0.75rem">' + esc(lang('comment')) + '</label>' +
			'<textarea id="modal-order-comment" class="app-show__modal-textarea" rows="2"></textarea>';

		// ── Special requirements ──
		body += '<label class="app-show__modal-label" for="modal-order-special" style="margin-top:0.75rem">' + esc(lang('specialRequirements')) + '</label>' +
			'<textarea id="modal-order-special" class="app-show__modal-textarea" rows="2"></textarea>';

		var footer = '<button type="button" class="ds-button" data-variant="secondary" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="ds-button" id="modal-order-submit">' + esc(lang('save')) + '</button>';

		opts.showModal('order-dialog', lang('createOrder'), body, footer);

		// ── Serving time state & helpers ──
		var _allAppDates = [];
		var _appDates = [];
		var _selectedLocId = null;
		var _appResourceIds = null; // resource IDs from the selected application's dates
		var _allLocations = null;   // full unfiltered location list from API
		var _currentHospMainResourceId = hospMainResourceId;
		var dateSelect = document.getElementById('modal-order-serving-date');
		var timeSelect = document.getElementById('modal-order-serving-time');

		function generate15MinIntervals(from, to) {
			var slots = [];
			var start = new Date(from);
			var end = new Date(to);
			var mins = start.getMinutes();
			var rem = mins % 15;
			if (rem > 0) start.setMinutes(mins + (15 - rem), 0, 0);
			else start.setSeconds(0, 0);
			while (start <= end) {
				slots.push(new Date(start));
				start.setMinutes(start.getMinutes() + 15);
			}
			return slots;
		}

		function fmtTime(d) {
			return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
		}

		function fmtShortDate(d) {
			return d.toLocaleDateString('nb-NO', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
		}

		function fmtNaiveIso(d) {
			return d.getFullYear() + '-' +
				String(d.getMonth() + 1).padStart(2, '0') + '-' +
				String(d.getDate()).padStart(2, '0') + 'T' +
				String(d.getHours()).padStart(2, '0') + ':' +
				String(d.getMinutes()).padStart(2, '0') + ':00';
		}

		function populateTimeSlots(dateIdx) {
			timeSelect.innerHTML = '<option value="">' + esc(lang('selectTime')) + '</option>';
			timeSelect.disabled = true;
			if (dateIdx === '' || !_appDates[dateIdx]) return;

			var range = _appDates[dateIdx];
			var slots = generate15MinIntervals(range.from_, range.to_);
			slots.forEach(function (slot) {
				var opt = document.createElement('option');
				opt.value = fmtNaiveIso(slot);
				opt.textContent = fmtTime(slot);
				timeSelect.appendChild(opt);
			});
			timeSelect.disabled = false;
		}

		function filterAndPopulateDates() {
			_appDates = [];
			dateSelect.innerHTML = '<option value="">' + esc(lang('selectDate')) + '</option>';
			dateSelect.disabled = true;
			timeSelect.innerHTML = '<option value="">' + esc(lang('selectTime')) + '</option>';
			timeSelect.disabled = true;

			if (_allAppDates.length === 0) return;

			var locId = _selectedLocId ? parseInt(_selectedLocId, 10) : null;
			if (locId && locId !== _currentHospMainResourceId) {
				_appDates = _allAppDates.filter(function (d) {
					return d.resources && d.resources.indexOf(locId) !== -1;
				});
			} else {
				_appDates = _allAppDates.slice();
			}

			if (_appDates.length === 0) return;

			_appDates.forEach(function (d, i) {
				var opt = document.createElement('option');
				opt.value = i;
				opt.textContent = fmtShortDate(new Date(d.from_));
				dateSelect.appendChild(opt);
			});

			if (_appDates.length === 1) {
				dateSelect.value = '0';
				dateSelect.disabled = true;
				populateTimeSlots(0);
			} else {
				dateSelect.disabled = false;
			}
		}

		function loadAppDates(appId) {
			_allAppDates = [];
			_appDates = [];
			dateSelect.innerHTML = '<option value="">' + esc(lang('selectDate')) + '</option>';
			dateSelect.disabled = true;
			timeSelect.innerHTML = '<option value="">' + esc(lang('selectTime')) + '</option>';
			timeSelect.disabled = true;

			if (!appId || !applicationsBaseUrl) return;

			fetchJson(applicationsBaseUrl + '/' + appId + '/dates').then(function (dates) {
				_allAppDates = dates;

				// Collect all resource IDs from the application's dates
				_appResourceIds = {};
				dates.forEach(function (d) {
					if (d.resources) {
						d.resources.forEach(function (rid) { _appResourceIds[rid] = true; });
					}
				});

				// Filter location selector to only resources in this application.
				// Use _allLocations (full list) as source if available; otherwise mapResponse handles it on fetch.
				if (locSelector && _allLocations) {
					var filtered = _allLocations.filter(function (loc) {
						return _appResourceIds[loc.id];
					});
					locSelector.setItems(filtered);
				}

				filterAndPopulateDates();
			}).catch(function () {});
		}

		dateSelect.addEventListener('change', function () {
			populateTimeSlots(this.value);
		});

		// ── Location selector ──
		function initLocationSelector(locationsUrl) {
			var container = document.getElementById('modal-order-loc-container');
			// Clear any previous selector state
			var hiddenInput = container.querySelector('input[type="hidden"]');
			if (hiddenInput) hiddenInput.value = '';
			var textInput = container.querySelector('.search-select__input');
			if (textInput) textInput.value = '';

			return new SearchSelect(container, {
				apiUrl: locationsUrl,
				idField: 'id',
				labelField: '_label',
				mapResponse: function (data) {
					var locations = (Array.isArray(data) ? data : []).map(function (loc) {
						loc._label = loc.name + ' (' + loc.location_type + ')';
						return loc;
					});
					_allLocations = locations.slice();
					// Filter to only resources present in the selected application
					if (_appResourceIds) {
						locations = locations.filter(function (loc) {
							return _appResourceIds[loc.id];
						});
					}
					return locations;
				},
				placeholder: lang('location') + '...',
				emptyText: lang('noOrders'),
				onChange: function (id) {
					_selectedLocId = id;
					filterAndPopulateDates();
				}
			});
		}

		var locSelector = null;

		// ── Hospitality selector (application page context) ──
		var hospSelector = null;
		if (!fixedHospitalityId) {
			hospSelector = new SearchSelect(
				document.getElementById('modal-order-hosp-container'),
				{
					items: hospitalities,
					idField: 'id',
					labelField: 'name',
					placeholder: lang('hospitality') + '...',
					emptyText: lang('noOrders'),
					onChange: function (id) {
						_selectedHospitalityId = id ? parseInt(id, 10) : null;
						// Update main resource id for date filtering
						var selected = hospitalities.find(function (h) { return h.id == id; });
						_currentHospMainResourceId = selected ? parseInt(selected.resource_id, 10) : null;

						// Reset and reinitialize location selector for this hospitality
						_selectedLocId = null;
						_allAppDates = [];
						_appDates = [];
						dateSelect.innerHTML = '<option value="">' + esc(lang('selectDate')) + '</option>';
						dateSelect.disabled = true;
						timeSelect.innerHTML = '<option value="">' + esc(lang('selectTime')) + '</option>';
						timeSelect.disabled = true;

						if (id && deliveryLocationsBaseUrl) {
							var url = deliveryLocationsBaseUrl + '/' + id + '/delivery-locations';
							locSelector = initLocationSelector(url);
						}

						// If application is already known, load dates
						if (fixedApplicationId) {
							loadAppDates(fixedApplicationId);
						}
					}
				}
			);
		}

		// ── Application selector (hospitality page context) ──
		var appSelector = null;
		if (!fixedApplicationId) {
			appSelector = new SearchSelect(
				document.getElementById('modal-order-app-container'),
				{
					apiUrl: relevantAppsUrl,
					idField: 'id',
					labelField: '_label',
					mapResponse: function (data) {
						return (Array.isArray(data) ? data : []).map(function (app) {
							app._label = '#' + app.id + ' - ' + (app.status || '') + (app.contact_name ? ' (' + app.contact_name + ')' : '');
							return app;
						});
					},
					placeholder: '#ID...',
					emptyText: lang('noOrders'),
					onChange: function (id) {
						loadAppDates(id);
					}
				}
			);
		}

		// ── Initialize location selector if hospitality is already known ──
		if (fixedHospitalityId && deliveryLocationsUrl) {
			locSelector = initLocationSelector(deliveryLocationsUrl);
		}

		// ── If application is fixed, load dates immediately ──
		if (fixedApplicationId && fixedHospitalityId) {
			loadAppDates(fixedApplicationId);
		}

		// ── Submit handler ──
		document.getElementById('modal-order-submit').addEventListener('click', function () {
			var appId = fixedApplicationId;
			var locId = locSelector ? locSelector.getValue() : null;
			var hospitalityId = _selectedHospitalityId;

			if (!fixedApplicationId && appSelector) {
				appId = appSelector.getValue();
				// Allow typing a raw application ID
				if (!appId) {
					var rawInput = document.getElementById('modal-order-app-container').querySelector('.search-select__input').value.trim();
					var parsed = parseInt(rawInput.replace('#', ''), 10);
					if (parsed > 0) appId = parsed;
				}
			}

			if (!hospitalityId) {
				var hospContainer = document.getElementById('modal-order-hosp-container');
				if (hospContainer) hospContainer.querySelector('.search-select__input').focus();
				return;
			}
			if (!appId) {
				var appContainer = document.getElementById('modal-order-app-container');
				if (appContainer) appContainer.querySelector('.search-select__input').focus();
				return;
			}
			if (!locId) {
				document.getElementById('modal-order-loc-container').querySelector('.search-select__input').focus();
				return;
			}

			var servingTimeIso = timeSelect.value;
			if (!servingTimeIso) {
				timeSelect.focus();
				return;
			}

			// Determine the correct application_id from the selected date entry
			var selectedDateIdx = dateSelect.value;
			var targetAppId = parseInt(appId, 10);
			if (selectedDateIdx !== '' && _appDates[selectedDateIdx] && _appDates[selectedDateIdx].application_id) {
				targetAppId = _appDates[selectedDateIdx].application_id;
			}

			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			var payload = {
				application_id: targetAppId,
				hospitality_id: parseInt(hospitalityId, 10),
				location_resource_id: parseInt(locId, 10),
				serving_time_iso: servingTimeIso,
				comment: document.getElementById('modal-order-comment').value.trim() || null,
				special_requirements: document.getElementById('modal-order-special').value.trim() || null
			};

			postJson(ordersStoreUrl, payload).then(function () {
				opts.closeModal('order-dialog');
				opts.showToast(lang('saved'));
				if (opts.onSuccess) opts.onSuccess();
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('save');
				opts.showToast(lang('error') + ': ' + err.message, 'danger');
			});
		});
	}

	return { open: open };
})();
