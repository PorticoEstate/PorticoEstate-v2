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
 *       // For the kitchen's opening hours, which bound the times of an order served in the kitchen's
 *       // own room (hospitality page knows the building, app page gets it per-hospitality)
 *       hospBuildingId: 10,
 *       seasonsBaseUrl: '/bookingfrontend/buildings',   // + /{id}/seasons
 *       serverSettingsUrl: '/api/server-settings',
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

	function pad2(n) {
		return String(n).padStart(2, '0');
	}

	/**
	 * Whether an instant falls inside the opening hours defined by a set of seasons.
	 *
	 * A line-for-line port of isWithinBusinessHours in the citizen client
	 * (bookingfrontend/client/src/service/utils/business-hours.ts), which the citizen's order dialog uses
	 * for an on-site order (hospitality-order-modal.tsx, `timeSlots`). That file is TypeScript on luxon and
	 * cannot be loaded by this plain script, so the rule is repeated here; the harness in
	 * tests/hospitality-admin-order-modal compares the two over every quarter hour of a week. If the rule
	 * changes there, it changes here: read its comments first, the three exits below are deliberately asymmetric.
	 *
	 *   1. no seasons at all                -> open or closed per the admin setting
	 *   2. no ACTIVE season covers the date -> CLOSED
	 *   3. no boundaries that weekday       -> CLOSED
	 *
	 * An EMPTY `resourceIds` means "no filter" (every season matches); the caller must pass the kitchen's id.
	 */
	function isWithinBusinessHours(date, resourceIds, seasons, closeWhenNoSeasons) {
		if (!seasons || seasons.length === 0) {
			return !closeWhenNoSeasons;
		}
		var weekday = date.getDay() === 0 ? 7 : date.getDay(); // ISO: Monday = 1 ... Sunday = 7, as luxon's `weekday`
		var timeStr = pad2(date.getHours()) + ':' + pad2(date.getMinutes()) + ':' + pad2(date.getSeconds());
		var at = date.getTime();

		var activeSeasons = seasons.filter(function (season) {
			var hasMatchingResources = resourceIds.length === 0 ||
				(Array.isArray(season.resources) ? season.resources : []).some(function (r) {
					return resourceIds.indexOf(String(r.id)) !== -1;
				});
			return season.active && at >= Date.parse(season.from_) && at <= Date.parse(season.to_) && hasMatchingResources;
		});
		if (activeSeasons.length === 0) {
			return false;
		}

		var dayBoundaries = [];
		activeSeasons.forEach(function (season) {
			(season.boundaries || []).forEach(function (b) {
				if (b.wday === weekday) dayBoundaries.push(b);
			});
		});
		if (dayBoundaries.length === 0) {
			return false;
		}

		// A boundary reaching 23:45 or later ends at midnight. This extends the END only; it never opens the morning.
		return dayBoundaries.some(function (b) {
			var effectiveTo = b.to_ >= '23:45:00' ? '24:00:00' : b.to_;
			return b.from_ <= timeStr && effectiveTo >= timeStr;
		});
	}

	function open(opts) {
		var lang = opts.lang;
		var esc = opts.esc;
		var fetchJson = opts.fetchJson;
		var postJson = opts.postJson;

		var fixedHospitalityId = opts.hospitalityId || null;
		var fixedApplicationId = opts.applicationId || null;
		var hospitalities = opts.hospitalities || [];
		var hospMainResourceId = opts.hospMainResourceId || null;
		var hospBuildingId = opts.hospBuildingId || null;

		var seasonsBaseUrl = opts.seasonsBaseUrl || null;
		var serverSettingsUrl = opts.serverSettingsUrl || null;
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
			body += '<label class="app-show__modal-label booking-modal__label ds-modal-label">' + esc(lang('hospitality')) + ' *</label>' +
				'<div id="modal-order-hosp-container" class="search-select" data-booking-role="hospitality-selector">' +
				'<input type="text" class="search-select__input app-show__modal-textarea booking-modal__input" data-booking-role="hospitality-input" style="min-height:auto;height:2.25rem" placeholder="' + esc(lang('hospitality')) + '..." autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
				'<input type="hidden" id="modal-order-hosp-value">' +
				'<ul class="search-select__dropdown" role="listbox"></ul>' +
				'</div>';
		}

		// ── Application selector (only when not fixed) ──
		if (!fixedApplicationId) {
			body += '<label class="app-show__modal-label booking-modal__label ds-modal-label"' + (!fixedHospitalityId ? ' style="margin-top:0.75rem"' : '') + '>' + esc(lang('application')) + ' *</label>' +
				'<div id="modal-order-app-container" class="search-select" data-booking-role="application-selector">' +
				'<input type="text" class="search-select__input app-show__modal-textarea booking-modal__input" data-booking-role="application-input" style="min-height:auto;height:2.25rem" placeholder="#ID..." autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
				'<input type="hidden" id="modal-order-app-value">' +
				'<ul class="search-select__dropdown" role="listbox"></ul>' +
				'</div>';
		}

		// ── Location selector ──
		body += '<label class="app-show__modal-label booking-modal__label ds-modal-label" style="margin-top:0.75rem">' + esc(lang('location')) + ' *</label>' +
			'<div id="modal-order-loc-container" class="search-select" data-booking-role="location-selector">' +
			'<input type="text" class="search-select__input app-show__modal-textarea booking-modal__input" data-booking-role="location-input" style="min-height:auto;height:2.25rem" placeholder="' + esc(lang('location')) + '..." autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
			'<input type="hidden" id="modal-order-loc-value">' +
			'<ul class="search-select__dropdown" role="listbox"></ul>' +
			'</div>';

		// ── Serving time ──
		body += '<label class="app-show__modal-label booking-modal__label ds-modal-label" style="margin-top:0.75rem">' + esc(lang('servingTime')) + ' *</label>' +
			'<div id="modal-order-serving-container" style="display:flex;gap:0.5rem">' +
			'<select id="modal-order-serving-date" class="app-show__modal-textarea booking-modal__input" data-booking-role="serving-date" style="min-height:auto;height:2.25rem;flex:1;max-width:10rem" disabled>' +
			'<option value="">' + esc(lang('selectDate')) + '</option></select>' +
			'<select id="modal-order-serving-time" class="app-show__modal-textarea booking-modal__input" data-booking-role="serving-time" style="min-height:auto;height:2.25rem;flex:1;max-width:10rem" disabled>' +
			'<option value="">' + esc(lang('selectTime')) + '</option></select>' +
			'</div>';

		// ── Comment ──
		body += '<label class="app-show__modal-label booking-modal__label ds-modal-label" for="modal-order-comment" style="margin-top:0.75rem">' + esc(lang('comment')) + '</label>' +
			'<textarea id="modal-order-comment" class="app-show__modal-textarea booking-modal__input" data-booking-role="comment" rows="2"></textarea>';

		// ── Special requirements ──
		body += '<label class="app-show__modal-label booking-modal__label ds-modal-label" for="modal-order-special" style="margin-top:0.75rem">' + esc(lang('specialRequirements')) + '</label>' +
			'<textarea id="modal-order-special" class="app-show__modal-textarea booking-modal__input" data-booking-role="special-requirements" rows="2"></textarea>';

		var footer = '<button type="button" class="booking-button ds-button" data-variant="secondary" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="booking-button ds-button" id="modal-order-submit" data-booking-action="submit-order">' + esc(lang('save')) + '</button>';

		opts.showModal('order-dialog', lang('createOrder'), body, footer);

		// ── Serving time state & helpers ──
		var _allAppDates = [];
		var _appDates = [];
		var _selectedLocId = null;
		var _appResourceIds = null; // resource IDs from the selected application's dates
		var _allLocations = null;   // full unfiltered location list from API
		var _currentHospMainResourceId = hospMainResourceId;
		var _currentHospBuildingId = hospBuildingId;
		var _selectedLocIsMain = false; // the chosen location is the kitchen's own room (served on site)
		var _hoursByBuilding = {};      // building id -> {seasons, closeWhenNoSeasons} once fetched, false when the fetch failed
		var _hoursRequested = {};       // building id -> true while its request is in flight
		var _awaitingHours = false;     // the time list is empty until the hours arrive
		var dateSelect = document.querySelector('[data-booking-role="serving-date"]');
		var timeSelect = document.querySelector('[data-booking-role="serving-time"]');

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

		function fmtLocalIso(d) {
			var pad = function (n) { return String(n).padStart(2, '0'); };
			// Offset in minutes east of UTC (getTimezoneOffset is minutes behind UTC)
			var offMin = -d.getTimezoneOffset();
			var sign = offMin >= 0 ? '+' : '-';
			var absOff = Math.abs(offMin);
			return d.getFullYear() + '-' +
				pad(d.getMonth() + 1) + '-' +
				pad(d.getDate()) + 'T' +
				pad(d.getHours()) + ':' +
				pad(d.getMinutes()) + ':00' +
				sign + pad(Math.floor(absOff / 60)) + ':' + pad(absOff % 60);
		}

		/**
		 * Opening hours of the building that owns the KITCHEN resource: its seasons, and whether a building
		 * without seasons is closed. The same two sources the citizen dialog reads (useBuildingSeasons and
		 * useServerSettings in bookingfrontend/client), both public GETs. Fetched on the first need, once
		 * per building.
		 */
		function requestKitchenHours(buildingId) {
			var key = String(buildingId);
			if (_hoursRequested[key]) return;
			_hoursRequested[key] = true;

			Promise.all([
				fetchJson(seasonsBaseUrl + '/' + encodeURIComponent(buildingId) + '/seasons'),
				// A setting that cannot be read is «false», as the citizen dialog reads it (`?? false`): the seasons still bound the times.
				fetchJson(serverSettingsUrl + '?include_configs=true').catch(function () { return null; })
			]).then(function (res) {
				// Anything but a list is not "no seasons": it is a failed fetch.
				if (!Array.isArray(res[0])) throw new Error('seasons');
				var settings = res[1] && res[1].bookingfrontend_config;
				_hoursByBuilding[key] = {
					seasons: res[0],
					closeWhenNoSeasons: !!(settings && settings.close_calendar_without_season)
				};
			}).catch(function (err) {
				// The bound is a convenience with no server rule behind it, and it must never stop a case officer from
				// ordering: hours that cannot be fetched leave the times as the application's own, as without the bound.
				// The failure is said out loud, once; the answer is kept, so the same dialog does not ask again.
				_hoursByBuilding[key] = false;
				opts.showToast(lang('error') + ': ' + err.message, 'danger');
			}).then(function () {
				delete _hoursRequested[key];
				var waiting = _awaitingHours;
				_awaitingHours = false;
				// Still on the kitchen's room, still on a date: fill the list.
				if (waiting) populateTimeSlots(dateSelect.value);
			});
		}

		/**
		 * The slots that fall inside the kitchen's opening hours, as the citizen dialog bounds an on-site
		 * order; null while the hours are on their way. Where the hours cannot be judged (no kitchen resource,
		 * no building whose seasons define them, no place to read them from, or a fetch that failed) there is
		 * no bound: the application's own times, as without it.
		 */
		function slotsWithinKitchenHours(slots) {
			if (!_currentHospMainResourceId || !_currentHospBuildingId || !seasonsBaseUrl || !serverSettingsUrl) return slots;
			var hours = _hoursByBuilding[String(_currentHospBuildingId)];
			if (hours === undefined) {
				requestKitchenHours(_currentHospBuildingId);
				return null;
			}
			if (hours === false) return slots;
			var kitchen = [String(_currentHospMainResourceId)];
			return slots.filter(function (slot) {
				return isWithinBusinessHours(slot, kitchen, hours.seasons, hours.closeWhenNoSeasons);
			});
		}

		function populateTimeSlots(dateIdx) {
			timeSelect.innerHTML = '<option value="">' + esc(lang('selectTime')) + '</option>';
			timeSelect.disabled = true;
			_awaitingHours = false;
			if (dateIdx === '' || !_appDates[dateIdx]) return;

			var range = _appDates[dateIdx];
			var slots = generate15MinIntervals(range.from_, range.to_);
			// An order served in the kitchen's own room is bounded by the kitchen's opening hours, as in the citizen
			// dialog; a remote room is not (the food is eaten away from the kitchen: the application's own times rule).
			if (_selectedLocIsMain) {
				slots = slotsWithinKitchenHours(slots);
				if (slots === null) {
					_awaitingHours = true;
					return;
				}
			}
			slots.forEach(function (slot) {
				var opt = document.createElement('option');
				opt.value = fmtLocalIso(slot);
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

				// Filter location selector to the kitchen's own room and the resources in this application.
				// Use _allLocations (full list) as source if available; otherwise mapResponse handles it on fetch.
				if (locSelector && _allLocations) {
					locSelector.setItems(_allLocations.filter(isOfferedLocation));
				}

				filterAndPopulateDates();
			}).catch(function () {});
		}

		dateSelect.addEventListener('change', function () {
			populateTimeSlots(this.value);
		});

		/**
		 * May the admin put this delivery location on an order? The kitchen's own room («main»: the food is served
		 * on site and the room needs no booking) always; a remote room only when the application booked it. The
		 * same rule as the citizen dialog (bookingfrontend client, hospitality-order-modal.tsx, availableLocations).
		 * Only called once the application's resources are known.
		 */
		function isOfferedLocation(loc) {
			return loc.location_type === 'main' || !!_appResourceIds[loc.id];
		}

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
					// Filter to the kitchen's own room and the resources present in the selected application
					if (_appResourceIds) {
						locations = locations.filter(isOfferedLocation);
					}
					return locations;
				},
				placeholder: lang('location') + '...',
				emptyText: lang('noDeliveryLocations'),
				onChange: function (id, name, item) {
					_selectedLocId = id;
					_selectedLocIsMain = !!item && item.location_type === 'main';
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
						// The building that owns the kitchen resource: its seasons are the kitchen's opening hours
						_currentHospBuildingId = selected ? parseInt(selected.building_id, 10) || null : null;

						// Reset and reinitialize location selector for this hospitality
						_selectedLocId = null;
						_selectedLocIsMain = false;
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
							// Translate the application status via the injected lang();
							// fall back to the raw value if the key is missing.
							var st = '';
							if (app.status) {
								var key = String(app.status).toLowerCase();
								st = lang(key);
								if (!st || st === key || st.charAt(0) === '!') st = app.status;
							}
							app._label = '#' + app.id + (st ? ' - ' + st : '') + (app.contact_name ? ' (' + app.contact_name + ')' : '');
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
