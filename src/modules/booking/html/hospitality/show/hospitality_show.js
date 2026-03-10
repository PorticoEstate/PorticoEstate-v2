(function () {
	'use strict';

	var root = document.getElementById('hospitality-show');
	if (!root) return;

	var apiUrl = root.dataset.apiUrl.split('?')[0];
	var ordersUrl = root.dataset.ordersUrl.split('?')[0] + '?hospitality_id=' + root.dataset.hospitalityId;
	var resourcesApiUrl = root.dataset.resourcesUrl.split('?')[0];
	var buildingsUrl = root.dataset.buildingsUrl ? root.dataset.buildingsUrl.split('?')[0] : null;
	var articleMappingUrl = root.dataset.articleMappingUrl ? root.dataset.articleMappingUrl.split('?')[0] : null;
	var taxListUrl = root.dataset.taxListUrl ? root.dataset.taxListUrl.split('?')[0] : null;
	var canWrite = root.dataset.canWrite === '1';

	// ═══════════════════════════════════════════════════════════════════
	// Shared helpers
	// ═══════════════════════════════════════════════════════════════════

	function lang(key) {
		var el = root.dataset;
		if (el['lang' + key]) return el['lang' + key];
		var camelKey = 'lang' + key.split(/[-_]/).map(function (w) {
			return w.charAt(0).toUpperCase() + w.slice(1);
		}).join('');
		return el[camelKey] || key;
	}

	function esc(str) {
		if (str == null) return '';
		var div = document.createElement('div');
		div.textContent = String(str);
		return div.innerHTML;
	}

	function fmtDate(str) {
		if (!str) return '';
		var d = new Date(str);
		if (isNaN(d)) return esc(str);
		return d.toLocaleDateString('nb-NO', {
			day: '2-digit', month: '2-digit', year: 'numeric',
			hour: '2-digit', minute: '2-digit'
		});
	}

	function section(title, bodyHtml, opts) {
		opts = opts || {};
		var headerExtra = opts.headerHtml || '';
		return '<div class="app-show__section">' +
			'<div class="app-show__section-header"><h3>' + esc(title) + '</h3>' + headerExtra + '</div>' +
			'<div class="app-show__section-body">' + (bodyHtml || '<p class="app-show__empty">&mdash;</p>') + '</div>' +
			'</div>';
	}

	function field(label, value) {
		if (value == null || value === '') return '';
		return '<div class="app-show__field">' +
			'<span class="app-show__label">' + esc(label) + '</span>' +
			'<span class="app-show__value">' + esc(value) + '</span>' +
			'</div>';
	}

	function fieldHtml(label, valueHtml) {
		if (!valueHtml) return '';
		return '<div class="app-show__field">' +
			'<span class="app-show__label">' + esc(label) + '</span>' +
			'<span class="app-show__value">' + valueHtml + '</span>' +
			'</div>';
	}

	function showToast(message, type) {
		var toast = document.createElement('div');
		toast.className = 'app-alert app-alert-' + (type || 'success') + ' app-show__toast';
		toast.textContent = message;
		document.body.appendChild(toast);
		setTimeout(function () { toast.remove(); }, 3000);
	}

	function fetchJson(url) {
		return fetch(url, { credentials: 'same-origin' }).then(function (res) {
			if (!res.ok) throw new Error('HTTP ' + res.status);
			return res.json();
		});
	}

	function sendJson(method, url, data, extraHeaders) {
		var headers = { 'Content-Type': 'application/json' };
		if (extraHeaders) {
			Object.keys(extraHeaders).forEach(function (k) { headers[k] = extraHeaders[k]; });
		}
		return fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(data || {})
		}).then(function (res) {
			return res.json().then(function (json) {
				if (res.status === 409 && json.error === 'CONFLICT') {
					var err = new Error('CONFLICT');
					err.conflict = true;
					err.current = json.current;
					throw err;
				}
				if (!res.ok) throw new Error(json.error || 'HTTP ' + res.status);
				return json;
			});
		});
	}

	function putJson(url, data, extraHeaders) { return sendJson('PUT', url, data, extraHeaders); }
	function postJson(url, data) { return sendJson('POST', url, data); }
	function patchJson(url, data) { return sendJson('PATCH', url, data); }
	function deleteJson(url) {
		return fetch(url, { method: 'DELETE', credentials: 'same-origin' }).then(function (res) {
			if (!res.ok) throw new Error('HTTP ' + res.status);
			return res.json();
		});
	}

	var penIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.85 0 014 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
	var trashIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>';
	var chevronIcon = '<svg class="hosp-show__group-toggle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>';
	var backArrow = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>';
	var gripIcon = '<svg class="hosp-show__drag-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.5"/><circle cx="15" cy="5" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="15" cy="19" r="1.5"/></svg>';

	// ═══════════════════════════════════════════════════════════════════
	// State
	// ═══════════════════════════════════════════════════════════════════

	var hospitalityData = null;
	var ordersData = [];

	// ═══════════════════════════════════════════════════════════════════
	// Data fetching & initialization
	// ═══════════════════════════════════════════════════════════════════

	function loadData() {
		return Promise.all([
			fetchJson(apiUrl),
			fetchJson(ordersUrl)
		]);
	}

	loadData().then(function (results) {
		hospitalityData = results[0];
		ordersData = results[1];
		render();
	}).catch(function (err) {
		document.getElementById('hospitality-loading').hidden = true;
		var errEl = document.getElementById('hospitality-error');
		errEl.hidden = false;
		document.getElementById('hospitality-error-message').textContent =
			lang('error') + ': ' + err.message;
	});

	function refreshData() {
		loadData().then(function (results) {
			hospitalityData = results[0];
			ordersData = results[1];
			renderHeader(hospitalityData);
			renderDetails(hospitalityData);
			renderResources(hospitalityData);
			renderArticles(hospitalityData);
			renderOrders(ordersData);
		}).catch(function (err) {
			showToast(lang('error') + ': ' + err.message, 'danger');
		});
	}

	function refreshSection(section) {
		fetchJson(apiUrl).then(function (data) {
			hospitalityData = data;
			renderHeader(hospitalityData);
			switch (section) {
				case 'details':
					renderDetails(hospitalityData);
					break;
				case 'resources':
					// If DOM already has building groups, just patch toggle states
					if (document.getElementById('resource-building-groups') &&
						document.getElementById('resource-building-groups').children.length > 0) {
						patchResourceToggles();
					} else {
						renderResources(hospitalityData);
					}
					break;
				case 'articles':
					renderArticles(hospitalityData);
					break;
				case 'orders':
					fetchJson(ordersUrl).then(function (orders) {
						ordersData = orders;
						renderOrders(ordersData);
					});
					break;
				default:
					renderDetails(hospitalityData);
					if (document.getElementById('resource-building-groups') &&
						document.getElementById('resource-building-groups').children.length > 0) {
						patchResourceToggles();
					} else {
						renderResources(hospitalityData);
					}
					renderArticles(hospitalityData);
			}
		}).catch(function (err) {
			showToast(lang('error') + ': ' + err.message, 'danger');
		});
	}

	// ═══════════════════════════════════════════════════════════════════
	// Tab switching
	// ═══════════════════════════════════════════════════════════════════

	root.addEventListener('click', function (e) {
		var tab = e.target.closest('.app-show__tab');
		if (!tab) return;

		var tabName = tab.dataset.tab;
		root.querySelectorAll('.app-show__tab').forEach(function (t) {
			var isActive = t === tab;
			t.classList.toggle('app-show__tab--active', isActive);
			t.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});
		root.querySelectorAll('.app-show__tab-content').forEach(function (tc) {
			var id = tc.id.replace('tab-', '');
			tc.hidden = id !== tabName;
		});
		history.replaceState(null, '', '#' + tabName);
	});

	window.addEventListener('hashchange', function () {
		var hash = window.location.hash.replace('#', '');
		var tab = root.querySelector('[data-tab="' + hash + '"]');
		if (tab && !tab.classList.contains('app-show__tab--active')) tab.click();
	});

	// ═══════════════════════════════════════════════════════════════════
	// Main render
	// ═══════════════════════════════════════════════════════════════════

	function render() {
		document.getElementById('hospitality-loading').hidden = true;
		document.getElementById('hospitality-content').hidden = false;

		renderHeader(hospitalityData);
		renderDetails(hospitalityData);
		renderResources(hospitalityData);
		renderArticles(hospitalityData);
		renderOrders(ordersData);

		// Activate tab from URL hash
		var hash = window.location.hash.replace('#', '');
		if (hash && document.getElementById('tab-' + hash)) {
			var tab = root.querySelector('[data-tab="' + hash + '"]');
			if (tab) tab.click();
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// Header
	// ═══════════════════════════════════════════════════════════════════

	function renderHeader(h) {
		var activeTag = h.active
			? '<span class="ds-tag" data-color="success">' + esc(lang('active')) + '</span>'
			: '<span class="ds-tag" data-color="danger">' + esc(lang('inactive')) + '</span>';

		var html = '<a href="/booking/view/hospitality" class="hosp-show__back-link">' +
			backArrow + ' ' + esc(lang('backToList')) + '</a>';
		html += '<div class="app-show__title-row">' +
			'<div class="app-show__title-left">' +
			'<h1 class="app-show__title">' + esc(h.name) + '</h1>' +
			activeTag +
			'</div></div>';

		html += '<div class="app-show__meta">';
		html += '<span class="app-show__meta-item">' + lang('resource') + ': ' + esc(h.resource_name) + '</span>';
		html += '<span class="app-show__meta-item">' + lang('created') + ': ' + fmtDate(h.created) + '</span>';
		if (h.modified) {
			html += '<span class="app-show__meta-item">' + lang('modified') + ': ' + fmtDate(h.modified) + '</span>';
		}
		html += '</div>';

		document.getElementById('hospitality-header').innerHTML = html;
	}

	// ═══════════════════════════════════════════════════════════════════
	// Inline editing system
	// ═══════════════════════════════════════════════════════════════════

	function editableField(label, displayValue, fieldName, fieldType, opts) {
		opts = opts || {};
		var displayHtml = '';
		var descHtml = opts.description
			? '<div class="hosp-show__field-desc">' + esc(opts.description) + '</div>'
			: '';

		if (fieldType === 'checkbox') {
			displayHtml = displayValue ? lang('yes') : lang('no');
		} else {
			displayHtml = esc(displayValue != null ? displayValue : '');
		}

		if (!canWrite) {
			return fieldHtml(label, (displayHtml || '&mdash;') + descHtml);
		}

		return '<div class="app-show__field" data-editable="' + esc(fieldName) + '" data-field-type="' + esc(fieldType || 'text') + '">' +
			'<span class="app-show__label">' + esc(label) + '</span>' +
			'<span class="app-show__value">' +
			'<span class="hosp-show__display">' + (displayHtml || '&mdash;') + '</span>' +
			'<button type="button" class="hosp-show__edit-trigger" title="' + esc(lang('edit')) + '">' + penIcon + '</button>' +
			descHtml +
			'</span></div>';
	}

	function deadlineField(label, value, unit, opts) {
		opts = opts || {};
		var displayHtml = '';
		if (value && unit) {
			displayHtml = esc(value) + ' ' + esc(lang(unit));
		} else {
			displayHtml = '&mdash;';
		}

		var descHtml = opts.description
			? '<div class="hosp-show__field-desc">' + esc(opts.description) + '</div>'
			: '';

		if (!canWrite) {
			return fieldHtml(label, displayHtml + descHtml);
		}

		return '<div class="app-show__field" data-editable="order_deadline" data-field-type="compound">' +
			'<span class="app-show__label">' + esc(label) + '</span>' +
			'<span class="app-show__value">' +
			'<span class="hosp-show__display">' + displayHtml + '</span>' +
			'<button type="button" class="hosp-show__edit-trigger" title="' + esc(lang('edit')) + '">' + penIcon + '</button>' +
			descHtml +
			'</span></div>';
	}

	// Helper: notify collab about edit start/stop
	function collabStartEditing(scope) {
		if (window.__hospWs && window.__hospWs.collab) {
			window.__hospWs.collab.startEditing(scope);
		}
	}
	function collabStopEditing(scope) {
		if (window.__hospWs && window.__hospWs.collab) {
			window.__hospWs.collab.stopEditing(scope);
		}
	}

	// Delegated click handler for pen icons
	root.addEventListener('click', function (e) {
		var trigger = e.target.closest('.hosp-show__edit-trigger');
		if (!trigger) return;

		var fieldEl = trigger.closest('[data-editable]');
		if (!fieldEl) return;

		var fieldName = fieldEl.dataset.editable;
		var fieldType = fieldEl.dataset.fieldType || 'text';
		var valueSpan = fieldEl.querySelector('.app-show__value');
		var currentDisplay = fieldEl.querySelector('.hosp-show__display');

		var currentValue;
		if (fieldType === 'checkbox') {
			currentValue = hospitalityData[fieldName] ? true : false;
		} else if (fieldType === 'compound') {
			// deadline compound
			currentValue = {
				value: hospitalityData.order_by_time_value || '',
				unit: hospitalityData.order_by_time_unit || 'hours'
			};
		} else {
			currentValue = hospitalityData[fieldName] != null ? String(hospitalityData[fieldName]) : '';
		}

		var formHtml = '<div class="hosp-show__edit-form">';

		if (fieldType === 'textarea') {
			formHtml += '<textarea class="hosp-show__edit-input">' + esc(currentValue) + '</textarea>';
		} else if (fieldType === 'checkbox') {
			formHtml += '<label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer">' +
				'<input type="checkbox" class="hosp-show__edit-input"' + (currentValue ? ' checked' : '') + '> ' +
				esc(lang('active')) + '</label>';
		} else if (fieldType === 'compound') {
			formHtml += '<div class="hosp-show__compound-field">' +
				'<input type="number" class="hosp-show__edit-input" data-sub="value" value="' + esc(currentValue.value) + '" min="0">' +
				'<select class="hosp-show__edit-input" data-sub="unit">' +
				'<option value="hours"' + (currentValue.unit === 'hours' ? ' selected' : '') + '>' + esc(lang('hours')) + '</option>' +
				'<option value="days"' + (currentValue.unit === 'days' ? ' selected' : '') + '>' + esc(lang('days')) + '</option>' +
				'</select></div>';
		} else {
			formHtml += '<input type="text" class="hosp-show__edit-input" value="' + esc(currentValue) + '">';
		}

		formHtml += '<div class="hosp-show__edit-actions">' +
			'<button type="button" class="hosp-show__edit-save">' + esc(lang('save')) + '</button>' +
			'<button type="button" class="hosp-show__edit-cancel">' + esc(lang('cancel')) + '</button>' +
			'</div></div>';

		valueSpan.innerHTML = formHtml;

		var editScope = 'field:' + fieldName;
		collabStartEditing(editScope);

		var input = valueSpan.querySelector('.hosp-show__edit-input');
		if (input && input.tagName !== 'DIV') input.focus();

		// Save handler
		valueSpan.querySelector('.hosp-show__edit-save').addEventListener('click', function () {
			var payload = {};

			if (fieldType === 'checkbox') {
				payload[fieldName] = input.checked ? 1 : 0;
			} else if (fieldType === 'compound') {
				var valInput = valueSpan.querySelector('[data-sub="value"]');
				var unitInput = valueSpan.querySelector('[data-sub="unit"]');
				payload.order_by_time_value = valInput.value ? parseInt(valInput.value, 10) : null;
				payload.order_by_time_unit = unitInput.value;
			} else {
				payload[fieldName] = input.value;
			}

			this.disabled = true;
			this.textContent = '...';

			// Include modified timestamp for conflict detection
			var headers = {};
			if (hospitalityData && hospitalityData.modified) {
				headers['X-If-Modified-Since'] = hospitalityData.modified;
			}

			putJson(apiUrl, payload, headers).then(function (updated) {
				collabStopEditing(editScope);
				hospitalityData = Object.assign(hospitalityData, updated);
				showToast(lang('saved'));
				renderDetails(hospitalityData);
				renderHeader(hospitalityData);
			}).catch(function (err) {
				collabStopEditing(editScope);
				if (err.conflict) {
					showConflictDialog(err.current, function () {
						// Retry without conflict check
						putJson(apiUrl, payload).then(function (updated) {
							hospitalityData = Object.assign(hospitalityData, updated);
							showToast(lang('saved'));
							renderDetails(hospitalityData);
							renderHeader(hospitalityData);
						}).catch(function (retryErr) {
							showToast(lang('error') + ': ' + retryErr.message, 'danger');
							renderDetails(hospitalityData);
						});
					});
				} else {
					showToast(lang('error') + ': ' + err.message, 'danger');
					renderDetails(hospitalityData);
				}
			});
		});

		// Cancel handler
		valueSpan.querySelector('.hosp-show__edit-cancel').addEventListener('click', function () {
			collabStopEditing(editScope);
			renderDetails(hospitalityData);
		});

		// Enter key to save (for single-line inputs)
		if (fieldType === 'text' || fieldType === 'number') {
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') valueSpan.querySelector('.hosp-show__edit-save').click();
				if (e.key === 'Escape') valueSpan.querySelector('.hosp-show__edit-cancel').click();
			});
		}
	});

	// ═══════════════════════════════════════════════════════════════════
	// Details tab
	// ═══════════════════════════════════════════════════════════════════

	function renderDetails(h) {
		var html = '';

		// Core info
		var coreHtml = '';
		coreHtml += editableField(lang('name'), h.name, 'name', 'text');
		coreHtml += editableField(lang('description'), h.description, 'description', 'textarea');
		coreHtml += editableField(lang('active'), h.active, 'active', 'checkbox');
		coreHtml += field(lang('resource'), h.resource_name);
		html += section(lang('details'), coreHtml);

		// Service configuration
		var svcHtml = '';
		svcHtml += editableField(lang('remoteServing'), h.remote_serving_enabled, 'remote_serving_enabled', 'checkbox', {
			description: lang('remoteServingDesc')
		});
		svcHtml += editableField(lang('allowOnSiteHospitality'), h.allow_on_site_hospitality, 'allow_on_site_hospitality', 'checkbox', {
			description: lang('allowOnSiteHospitalityDesc')
		});
		svcHtml += deadlineField(lang('orderDeadline'), h.order_by_time_value, h.order_by_time_unit, {
			description: lang('orderDeadlineDesc')
		});
		html += section(lang('serviceConfiguration'), svcHtml);

		// Metadata
		var metaHtml = '';
		metaHtml += field(lang('created'), fmtDate(h.created));
		metaHtml += field(lang('modified'), fmtDate(h.modified));
		html += section(lang('details'), metaHtml);

		document.getElementById('hospitality-details').innerHTML = html;
	}

	// ═══════════════════════════════════════════════════════════════════
	// Resources tab
	// ═══════════════════════════════════════════════════════════════════

	var _inlineBuildingSelect = null;

	function renderResourceToggleRow(r) {
		var html = '<div class="hosp-show__article-row' + (!r.isAdded ? ' hosp-show__article-row--dimmed' : '') + '">';
		html += '<span class="hosp-show__article-name">' + esc(r.resource_name) + '</span>';

		if (canWrite) {
			html += '<span class="hosp-show__article-active">' +
				'<label class="hosp-show__toggle">' +
				'<input type="checkbox" data-toggle-resource="' + r.resource_id + '"' +
				(r.isAdded ? ' checked' : '') +
				' data-resource-added="' + (r.isAdded ? '1' : '0') + '">' +
				'<span class="hosp-show__toggle-slider"></span></label></span>';
		} else {
			var tag = r.isAdded
				? '<span class="ds-tag" data-color="success">' + esc(lang('yes')) + '</span>'
				: '';
			if (tag) html += '<span class="hosp-show__article-active">' + tag + '</span>';
		}

		html += '</div>';
		return html;
	}

	function renderBuildingGroup(group, allBuildingResources) {
		var groupId = 'rl-building-' + (group.id || 'other');
		var addedMap = {};
		group.resources.forEach(function (loc) {
			addedMap[loc.resource_id] = loc;
		});

		var mainResourceId = hospitalityData.resource_id;

		// Determine rows
		var rows;
		if (allBuildingResources && canWrite) {
			// Writers see all resources in the building for quick toggle
			rows = allBuildingResources
				.filter(function (r) { return r.id !== mainResourceId; })
				.map(function (r) {
					return {
						resource_id: r.id,
						resource_name: r.name,
						isAdded: !!addedMap[r.id]
					};
				});
		} else {
			// Read-only or "Other" group — just show added resources
			rows = group.resources
				.filter(function (loc) { return loc.resource_id !== mainResourceId; })
				.map(function (loc) {
					return {
						resource_id: loc.resource_id,
						resource_name: loc.resource_name,
						isAdded: true
					};
				});
		}

		var addedCount = rows.filter(function (r) { return r.isAdded; }).length;
		var totalCount = rows.length;

		var html = '<div class="hosp-show__article-group" data-building-group="' + (group.id || 'other') + '">';
		html += '<div class="hosp-show__group-header" aria-expanded="true" data-group-toggle="' + groupId + '">';
		html += '<div class="hosp-show__group-title">' + chevronIcon + ' ' + esc(group.name) +
			' <span class="ds-tag" data-color="neutral">' + addedCount + ' / ' + totalCount + '</span></div>';
		html += '</div>';
		html += '<div class="hosp-show__group-body" data-group-body="' + groupId + '">';

		if (rows.length > 0) {
			rows.forEach(function (r) {
				html += renderResourceToggleRow(r);
			});
		} else {
			html += '<div class="hosp-show__article-empty">' + esc(lang('noRemoteLocations')) + '</div>';
		}

		html += '</div></div>';
		return html;
	}

	function populateBuildingGroups(locations) {
		var container = document.getElementById('resource-building-groups');

		// Group by building
		var buildingMap = {};
		var buildingOrder = [];
		locations.forEach(function (loc) {
			var key = loc.building_id ? String(loc.building_id) : '_other';
			if (!buildingMap[key]) {
				buildingMap[key] = {
					id: loc.building_id,
					name: loc.building_name || lang('other'),
					resources: []
				};
				buildingOrder.push(key);
			}
			buildingMap[key].resources.push(loc);
		});

		if (buildingOrder.length === 0) {
			container.innerHTML = '<p class="app-show__empty">' + esc(lang('noRemoteLocations')) + '</p>';
			return;
		}

		// Fetch all resources for each building (parallel) — only for writers
		var promises = buildingOrder.map(function (key) {
			var group = buildingMap[key];
			if (key === '_other' || !canWrite) {
				return Promise.resolve({ key: key, allResources: null });
			}
			return fetchJson(buildingsUrl + '/' + group.id + '/resources')
				.then(function (resources) { return { key: key, allResources: resources }; })
				.catch(function () { return { key: key, allResources: null }; });
		});

		Promise.all(promises).then(function (results) {
			var html = '';
			results.forEach(function (result) {
				var group = buildingMap[result.key];
				html += renderBuildingGroup(group, result.allResources);
			});
			container.innerHTML = html;
		});
	}

	function initInlineBuildingSearch(existingLocations) {
		var container = document.getElementById('inline-building-select');
		if (!container) return null;

		return new BuildingSelect(container, {
			apiUrl: buildingsUrl,
			onChange: function (buildingId, buildingName) {
				// If building already has a group, scroll to it
				var existingGroup = document.querySelector('[data-building-group="' + buildingId + '"]');
				if (existingGroup) {
					existingGroup.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					existingGroup.classList.add('hosp-show__highlight');
					setTimeout(function () { existingGroup.classList.remove('hosp-show__highlight'); }, 1500);
					// Clear search input
					container.querySelector('.building-select__input').value = '';
					return;
				}

				// Fetch resources for this building and add a new group
				fetchJson(buildingsUrl + '/' + buildingId + '/resources').then(function (resources) {
					var group = {
						id: buildingId,
						name: buildingName,
						resources: [] // no existing remote locations
					};
					var groupHtml = renderBuildingGroup(group, resources);

					var groupsContainer = document.getElementById('resource-building-groups');
					var emptyMsg = groupsContainer.querySelector('.app-show__empty');
					if (emptyMsg) emptyMsg.remove();

					groupsContainer.insertAdjacentHTML('beforeend', groupHtml);

					// Scroll to the new group
					var newGroup = groupsContainer.querySelector('[data-building-group="' + buildingId + '"]');
					if (newGroup) {
						newGroup.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					}

					// Clear search input
					container.querySelector('.building-select__input').value = '';
				});
			}
		});
	}

	function renderResources(h) {
		// Dispose previous building select
		if (_inlineBuildingSelect) {
			_inlineBuildingSelect.dispose();
			_inlineBuildingSelect = null;
		}

		var html = '';

		// Info banner if remote serving disabled
		if (!h.remote_serving_enabled) {
			html += '<div class="hosp-show__info-banner hosp-show__info-banner--warning">' +
				'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg> ' +
				esc(lang('remoteServingDisabled')) +
				'</div>';
		}

		// Main resource (read-only)
		html += section(lang('mainResource'), field(lang('resource'), h.resource_name));

		// Inline building search (top, where add button used to be)
		if (canWrite) {
			html += '<div class="hosp-show__tab-actions">' +
				'<div id="inline-building-select" class="building-select" style="flex:1;max-width:20rem">' +
				'<input type="text" class="building-select__input app-input" autocomplete="off" ' +
				'placeholder="' + esc(lang('searchBuildings')) + '..." ' +
				'aria-expanded="false" aria-autocomplete="list" role="combobox">' +
				'<input type="hidden">' +
				'<ul class="building-select__dropdown" role="listbox"></ul>' +
				'</div></div>';
		}

		// Container for building groups (populated async)
		html += '<div id="resource-building-groups"></div>';

		document.getElementById('hospitality-resources').innerHTML = html;

		// Populate building groups from remote locations data
		var locations = h.remote_locations || [];
		populateBuildingGroups(locations);

		// Initialize inline building search
		if (canWrite) {
			_inlineBuildingSelect = initInlineBuildingSearch(locations);
		}
	}

	/**
	 * Lightweight DOM patch: sync toggle states from fresh hospitalityData
	 * without tearing down and rebuilding the whole resources tab.
	 */
	function patchResourceToggles() {
		var locations = hospitalityData.remote_locations || [];
		var addedIds = {};
		locations.forEach(function (loc) {
			addedIds[String(loc.resource_id)] = true;
		});

		// Patch every toggle already in the DOM
		var toggles = root.querySelectorAll('[data-toggle-resource]');
		toggles.forEach(function (toggle) {
			var rid = toggle.dataset.toggleResource;
			var shouldBeAdded = !!addedIds[rid];
			var currentlyAdded = toggle.dataset.resourceAdded === '1';

			if (shouldBeAdded !== currentlyAdded) {
				toggle.checked = shouldBeAdded;
				updateRowAndCount(toggle, shouldBeAdded);
			}
		});
	}

	function updateRowAndCount(toggle, added) {
		var row = toggle.closest('.hosp-show__article-row');
		if (row) {
			row.classList.toggle('hosp-show__article-row--dimmed', !added);
		}
		toggle.dataset.resourceAdded = added ? '1' : '0';

		// Update the count tag in the parent building group header
		var group = toggle.closest('[data-building-group]');
		if (group) {
			var countTag = group.querySelector('.hosp-show__group-header .ds-tag');
			if (countTag) {
				var allToggles = group.querySelectorAll('[data-toggle-resource]');
				var addedCount = 0;
				allToggles.forEach(function (t) {
					if (t.dataset.resourceAdded === '1') addedCount++;
				});
				countTag.textContent = addedCount + ' / ' + allToggles.length;
			}
		}
	}

	// Toggle resource on/off (add/remove remote location)
	root.addEventListener('change', function (e) {
		var toggle = e.target.closest('[data-toggle-resource]');
		if (!toggle) return;
		var resourceId = toggle.dataset.toggleResource;
		var wasAdded = toggle.dataset.resourceAdded === '1';

		if (toggle.checked && !wasAdded) {
			// Add as remote location — update DOM immediately
			updateRowAndCount(toggle, true);
			postJson(apiUrl + '/remote-locations', { resource_id: parseInt(resourceId, 10) })
				.then(function () {
					showToast(lang('saved'));
					// Silently refresh data in background
					fetchJson(apiUrl).then(function (data) { hospitalityData = data; });
				})
				.catch(function (err) {
					toggle.checked = false;
					updateRowAndCount(toggle, false);
					showToast(lang('error') + ': ' + err.message, 'danger');
				});
		} else if (!toggle.checked && wasAdded) {
			// Remove remote location — update DOM immediately
			updateRowAndCount(toggle, false);
			deleteJson(apiUrl + '/remote-locations/' + resourceId)
				.then(function () {
					showToast(lang('saved'));
					// Silently refresh data in background
					fetchJson(apiUrl).then(function (data) { hospitalityData = data; });
				})
				.catch(function (err) {
					toggle.checked = true;
					updateRowAndCount(toggle, true);
					showToast(lang('error') + ': ' + err.message, 'danger');
				});
		}
	});

	// ═══════════════════════════════════════════════════════════════════
	// Articles tab
	// ═══════════════════════════════════════════════════════════════════

	function renderArticles(h) {
		var html = '';
		var groups = h.article_groups || [];
		var allArticles = h.articles || [];

		// Add group button
		if (canWrite) {
			html += '<div class="hosp-show__tab-actions">' +
				'<button type="button" class="app-button app-button-sm" data-action="add-group">' + esc(lang('add')) + ' ' + esc(lang('group')) + '</button>' +
				'<button type="button" class="app-button app-button-sm" data-action="add-article">' + esc(lang('add')) + ' ' + esc(lang('article')) + '</button>' +
				'</div>';
		}

		// Container for group-level sorting
		html += '<div class="hosp-show__groups-container" data-dnd-groups>';

		// Groups as collapsible cards
		groups.forEach(function (group, gi) {
			var groupArticles = (group.articles || []);
			var activeTag = group.active
				? '<span class="ds-tag" data-color="success">' + esc(lang('active')) + '</span>'
				: '<span class="ds-tag" data-color="danger">' + esc(lang('inactive')) + '</span>';

			html += '<div class="hosp-show__article-group" data-group-id="' + group.id + '" data-group-index="' + gi + '">';
			html += '<div class="hosp-show__group-header" aria-expanded="true" data-group-toggle="' + group.id + '">';

			if (canWrite) {
				html += '<span class="hosp-show__drag-handle" data-drag-handle-group="' + group.id + '" title="Drag to reorder">' + gripIcon + '</span>';
			}

			html += '<div class="hosp-show__group-title">' + chevronIcon + ' ' + esc(group.name) + ' ' + activeTag +
				' <span class="ds-tag" data-color="neutral">' + groupArticles.length + '</span></div>';

			if (canWrite) {
				html += '<div class="hosp-show__group-actions">';
				if (!group.active) {
					html += '<button type="button" class="app-button app-button-sm" data-reactivate-group="' + group.id + '" title="' + esc(lang('active')) + '">&#x21bb;</button>';
				}
				html += '<button type="button" class="app-button app-button-sm" data-edit-group="' + group.id + '" title="' + esc(lang('edit')) + '">' + penIcon + '</button>' +
					'<button type="button" class="app-button app-button-sm app-button-danger" data-delete-group="' + group.id + '" title="' + esc(lang('delete')) + '">' + trashIcon + '</button>' +
					'</div>';
			}
			html += '</div>';

			html += '<div class="hosp-show__group-body" data-group-body="' + group.id + '" data-dnd-article-list="' + group.id + '">';
			html += renderArticleRows(groupArticles, group.id);
			html += '</div></div>';
		});

		html += '</div>'; // close groups-container

		// Ungrouped articles (always shown at bottom, draggable into groups)
		var ungrouped = allArticles.filter(function (a) { return !a.article_group_id; });
		html += '<div class="app-show__section">' +
			'<div class="app-show__section-header"><h3>' + esc(lang('ungroupedArticles')) + '</h3></div>' +
			'<div class="app-show__section-body" data-dnd-article-list="ungrouped">' +
			(ungrouped.length > 0 ? renderArticleRows(ungrouped, 'ungrouped') :
				'<div class="hosp-show__article-empty">' + esc(lang('noArticles')) + '</div>') +
			'</div></div>';

		if (groups.length === 0 && ungrouped.length === 0) {
			html += '<p class="app-show__empty">' + esc(lang('noArticles')) + '</p>';
		}

		document.getElementById('hospitality-articles').innerHTML = html;

		// Notify DnD module to (re-)initialize
		root.dispatchEvent(new CustomEvent('hospitality:articles-rendered', { bubbles: true }));
	}

	function renderArticleRows(articles, groupId) {
		if (!articles || articles.length === 0) {
			return '<div class="hosp-show__article-empty">' + esc(lang('noArticles')) + '</div>';
		}

		var html = '';
		articles.forEach(function (a, i) {
			var activeTag = a.active
				? '<span class="ds-tag" data-color="success">' + esc(lang('yes')) + '</span>'
				: '<span class="ds-tag" data-color="danger">' + esc(lang('no')) + '</span>';
			var basePrice = a.base_price != null ? Number(a.base_price).toFixed(2) : '—';
			var overridePrice = a.override_price != null ? Number(a.override_price).toFixed(2) : '—';
			var effectivePrice = a.effective_price != null ? Number(a.effective_price).toFixed(2) : '—';

			html += '<div class="hosp-show__article-row" data-article-id="' + a.id + '"' +
				(groupId ? ' data-group-id="' + groupId + '" data-article-index="' + i + '"' : '') + '>';

			if (canWrite && groupId) {
				html += '<span class="hosp-show__drag-handle" data-drag-handle-article="' + a.id + '" title="Drag to reorder">' + gripIcon + '</span>';
			}

			html += '<span class="hosp-show__article-name">' + esc(a.article_name || a.name) + '</span>';
			html += '<span class="hosp-show__article-unit">' + esc(a.unit) + '</span>';
			html += '<span class="hosp-show__article-price">' + basePrice + '</span>';
			html += '<span class="hosp-show__article-price">' + overridePrice + '</span>';
			html += '<span class="hosp-show__article-price hosp-show__article-price--effective">' + effectivePrice + '</span>';
			html += '<span class="hosp-show__article-active">' + activeTag + '</span>';

			if (canWrite) {
				html += '<span class="hosp-show__article-actions">' +
					'<button type="button" class="app-button app-button-sm" data-edit-article="' + a.id + '" title="' + esc(lang('edit')) + '">' + penIcon + '</button> ' +
					'<button type="button" class="app-button app-button-sm app-button-danger" data-delete-article="' + a.id + '" title="' + esc(lang('delete')) + '">' + trashIcon + '</button>' +
					'</span>';
			}

			html += '</div>';
		});

		return html;
	}

	// Group collapse/expand
	root.addEventListener('click', function (e) {
		var header = e.target.closest('[data-group-toggle]');
		if (!header) return;
		// Don't toggle when clicking action buttons
		if (e.target.closest('.hosp-show__group-actions')) return;

		var groupId = header.dataset.groupToggle;
		var body = root.querySelector('[data-group-body="' + groupId + '"]');
		var isExpanded = header.getAttribute('aria-expanded') === 'true';
		header.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
		body.hidden = isExpanded;
	});

	// Add group
	root.addEventListener('click', function (e) {
		if (!e.target.closest('[data-action="add-group"]')) return;
		showGroupModal(null);
	});

	// Edit group
	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-edit-group]');
		if (!btn) return;
		e.stopPropagation();
		var groupId = parseInt(btn.dataset.editGroup, 10);
		var group = (hospitalityData.article_groups || []).find(function (g) { return g.id === groupId; });
		if (group) showGroupModal(group);
	});

	// Delete group
	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-delete-group]');
		if (!btn) return;
		e.stopPropagation();
		if (!confirm(lang('confirmDelete'))) return;
		var groupId = btn.dataset.deleteGroup;
		btn.disabled = true;
		deleteJson(apiUrl + '/article-groups/' + groupId).then(function () {
			showToast(lang('saved'));
			refreshData();
		}).catch(function (err) {
			btn.disabled = false;
			showToast(lang('error') + ': ' + err.message, 'danger');
		});
	});

	// Reactivate group
	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-reactivate-group]');
		if (!btn) return;
		e.stopPropagation();
		var groupId = btn.dataset.reactivateGroup;
		btn.disabled = true;
		patchJson(apiUrl + '/article-groups/' + groupId + '/reactivate', {}).then(function () {
			showToast(lang('saved'));
			refreshData();
		}).catch(function (err) {
			btn.disabled = false;
			showToast(lang('error') + ': ' + err.message, 'danger');
		});
	});

	function showGroupModal(group) {
		var isEdit = !!group;
		var title = isEdit ? lang('edit') + ' ' + lang('group') : lang('add') + ' ' + lang('group');

		var body = '<label class="app-show__modal-label" for="modal-group-name">' + esc(lang('name')) + ' *</label>' +
			'<input type="text" id="modal-group-name" class="app-show__modal-textarea" style="min-height:auto;height:2.25rem" value="' + esc(isEdit ? group.name : '') + '">' +
			'<label class="app-show__modal-label" for="modal-group-sort" style="margin-top:0.75rem">' + esc(lang('sortOrder')) + '</label>' +
			'<input type="number" id="modal-group-sort" class="app-show__modal-textarea" style="min-height:auto;height:2.25rem" value="' + (isEdit ? (group.sort_order || 0) : 0) + '">' +
			'<label class="app-show__modal-checkbox"><input type="checkbox" id="modal-group-active"' + (isEdit ? (group.active ? ' checked' : '') : ' checked') + '> ' + esc(lang('active')) + '</label>';

		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-primary" id="modal-group-submit">' + esc(lang('save')) + '</button>';

		showModal('group-dialog', title, body, footer);

		document.getElementById('modal-group-submit').addEventListener('click', function () {
			var name = document.getElementById('modal-group-name').value.trim();
			if (!name) { document.getElementById('modal-group-name').focus(); return; }

			var data = {
				name: name,
				sort_order: parseInt(document.getElementById('modal-group-sort').value, 10) || 0,
				active: document.getElementById('modal-group-active').checked ? 1 : 0
			};

			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			var promise = isEdit
				? putJson(apiUrl + '/article-groups/' + group.id, data)
				: postJson(apiUrl + '/article-groups', data);

			promise.then(function () {
				closeModal('group-dialog');
				showToast(lang('saved'));
				refreshData();
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('save');
				showToast(lang('error') + ': ' + err.message, 'danger');
			});
		});
	}

	// Add article
	root.addEventListener('click', function (e) {
		if (!e.target.closest('[data-action="add-article"]')) return;
		showArticleModal(null);
	});

	// Edit article
	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-edit-article]');
		if (!btn) return;
		var articleId = parseInt(btn.dataset.editArticle, 10);
		var article = (hospitalityData.articles || []).find(function (a) { return a.id === articleId; });
		if (article) showArticleModal(article);
	});

	// Delete article
	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-delete-article]');
		if (!btn) return;
		if (!confirm(lang('confirmDelete'))) return;
		var articleId = btn.dataset.deleteArticle;
		btn.disabled = true;
		deleteJson(apiUrl + '/articles/' + articleId).then(function () {
			showToast(lang('saved'));
			refreshData();
		}).catch(function (err) {
			btn.disabled = false;
			showToast(lang('error') + ': ' + err.message, 'danger');
		});
	});

	function showArticleModal(article) {
		var isEdit = !!article;
		var title = isEdit ? lang('edit') + ' ' + lang('article') : lang('add') + ' ' + lang('article');
		var groups = hospitalityData.article_groups || [];

		var body = '';

		if (!isEdit) {
			body += '<label class="app-show__modal-label">' + esc(lang('selectArticle')) + ' *</label>' +
				'<div id="modal-article-select-container" class="article-select">' +
				'<input type="text" class="article-select__input app-show__modal-textarea" style="min-height:auto;height:2.25rem" placeholder="' + esc(lang('selectArticle')) + '..." autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
				'<input type="hidden" id="modal-article-mapping-value">' +
				'<ul class="article-select__dropdown" role="listbox"></ul>' +
				'</div>';
		} else {
			body += '<p><strong>' + esc(article.article_name || article.name) + '</strong> (' + esc(article.unit) + ')</p>';
		}

		// Multi-language description
		body += '<div id="modal-article-desc-mlt" style="margin-top:0.75rem"></div>';

		// Group selector
		body += '<label class="app-show__modal-label" style="margin-top:0.75rem">' + esc(lang('group')) + '</label>' +
			'<div id="modal-group-select-container" class="search-select">' +
			'<input type="text" class="search-select__input app-show__modal-textarea" style="min-height:auto;height:2.25rem" autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
			'<input type="hidden" id="modal-article-group-value">' +
			'<ul class="search-select__dropdown" role="listbox"></ul>' +
			'</div>';

		body += '<label class="app-show__modal-label" for="modal-article-price" style="margin-top:0.75rem">' + esc(lang('overridePrice')) + '</label>' +
			'<input type="number" step="0.01" id="modal-article-price" class="app-show__modal-textarea" style="min-height:auto;height:2.25rem" value="' + (isEdit && article.override_price != null ? article.override_price : '') + '">';

		body += '<label class="app-show__modal-label" for="modal-article-sort" style="margin-top:0.75rem">' + esc(lang('sortOrder')) + '</label>' +
			'<input type="number" id="modal-article-sort" class="app-show__modal-textarea" style="min-height:auto;height:2.25rem" value="' + (isEdit ? (article.sort_order || 0) : 0) + '">';

		body += '<label class="app-show__modal-checkbox"><input type="checkbox" id="modal-article-active"' + (isEdit ? (article.active ? ' checked' : '') : ' checked') + '> ' + esc(lang('active')) + '</label>';

		// Expandable "Article Details" section (edit mode only)
		if (isEdit) {
			var chevronSvg = '<svg class="hosp-show__details-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>';
			body += '<div class="hosp-show__article-details-section">' +
				'<button type="button" class="hosp-show__details-toggle" id="article-details-toggle" aria-expanded="false">' +
				chevronSvg + ' ' + esc(lang('articleDetails')) +
				'</button>' +
				'<div class="hosp-show__details-body" id="article-details-body" hidden>' +
				'<div class="hosp-show__details-warning">' +
				'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> ' +
				esc(lang('articleDetailsWarning')) +
				'</div>' +
				'<div id="modal-detail-name-mlt" style="margin-top:0.75rem"></div>' +
				'<label class="app-show__modal-label" for="modal-detail-code" style="margin-top:0.75rem">' + esc(lang('articleCode')) + '</label>' +
				'<input type="text" id="modal-detail-code" class="app-show__modal-textarea" style="min-height:auto;height:2.25rem" value="' + esc(article.article_code || '') + '">' +
				'<label class="app-show__modal-label" for="modal-detail-unit" style="margin-top:0.75rem">' + esc(lang('unit')) + '</label>' +
				'<select id="modal-detail-unit" class="app-show__modal-textarea" style="min-height:auto;height:2.25rem">' +
				['each', 'kg', 'm', 'm2', 'minute', 'hour', 'day'].map(function (u) {
					return '<option value="' + u + '"' + (article.unit === u ? ' selected' : '') + '>' + u + '</option>';
				}).join('') +
				'</select>' +
				'<label class="app-show__modal-label" style="margin-top:0.75rem">' + esc(lang('taxCode')) + '</label>' +
				'<div id="modal-detail-tax-container" class="search-select">' +
				'<input type="text" class="search-select__input app-show__modal-textarea" style="min-height:auto;height:2.25rem" autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
				'<input type="hidden" id="modal-detail-tax-value">' +
				'<ul class="search-select__dropdown" role="listbox"></ul>' +
				'</div>' +
				'<label class="app-show__modal-label" for="modal-detail-base-price" style="margin-top:0.75rem">' + esc(lang('basePrice')) + '</label>' +
				'<input type="number" step="0.01" id="modal-detail-base-price" class="app-show__modal-textarea" style="min-height:auto;height:2.25rem" value="' + (article.base_price != null ? article.base_price : '') + '">' +
				'</div></div>';
		}

		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-primary" id="modal-article-submit">' + esc(lang('save')) + '</button>';

		showModal('article-dialog', title, body, footer);

		// Initialize multi-language description field
		var descMlt = null;
		var detailNameMlt = null;
		var detailTaxSelector = null;
		MultiLanguageText.fetchLanguages().then(function (langs) {
			descMlt = new MultiLanguageText(
				document.getElementById('modal-article-desc-mlt'),
				{
					languages: langs,
					label: lang('description'),
					inputType: 'textarea',
					placeholder: lang('description'),
					fallbackHintPrefix: lang('usesFallback'),
					value: isEdit ? (article.description || {}) : {}
				}
			);

			// Initialize article detail name MLT (edit mode only)
			if (isEdit && document.getElementById('modal-detail-name-mlt')) {
				detailNameMlt = new MultiLanguageText(
					document.getElementById('modal-detail-name-mlt'),
					{
						languages: langs,
						label: lang('name'),
						inputType: 'text',
						placeholder: lang('name'),
						fallbackHintPrefix: lang('usesFallback'),
						value: article.service_name_json || {}
					}
				);
			}
		});

		// Initialize article details collapsible toggle (edit mode only)
		if (isEdit) {
			var detailsToggle = document.getElementById('article-details-toggle');
			var detailsBody = document.getElementById('article-details-body');
			if (detailsToggle && detailsBody) {
				detailsToggle.addEventListener('click', function () {
					var expanded = this.getAttribute('aria-expanded') === 'true';
					this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
					detailsBody.hidden = expanded;
				});
			}

			// Initialize tax code SearchSelect for article details
			var taxContainer = document.getElementById('modal-detail-tax-container');
			if (taxContainer && taxListUrl) {
				detailTaxSelector = new SearchSelect(taxContainer, {
					apiUrl: taxListUrl,
					mapResponse: function (resp) {
						return Array.isArray(resp) ? resp : (resp.data || []);
					},
					placeholder: lang('taxCode') + '...',
					emptyText: lang('taxCode'),
					value: article.base_tax_code || null
				});
			}
		}

		// Initialize group search select
		var groupOpts = {
			items: groups,
			allowEmpty: true,
			emptyLabel: '-- ' + lang('ungroupedArticles') + ' --',
			allowCreate: true,
			createLabel: lang('add') + ' "{query}"',
			onCreate: function (name) {
				return postJson(apiUrl + '/article-groups', { name: name })
					.then(function (created) {
						return created;
					})
					.catch(function (err) {
						showToast(lang('error') + ': ' + err.message, 'danger');
						return null;
					});
			}
		};
		if (isEdit) {
			groupOpts.value = article.article_group_id || null;
		}
		var groupSelector = new SearchSelect(
			document.getElementById('modal-group-select-container'),
			groupOpts
		);

		// Initialize article search select for new articles
		var articleSelector = null;
		if (!isEdit) {
			var existingMappingIds = (hospitalityData.articles || []).map(function (a) { return a.article_mapping_id; });
			articleSelector = new ArticleSelect(
				document.getElementById('modal-article-select-container'),
				{
					category: 'service',
					excludeIds: existingMappingIds,
					emptyText: lang('noArticles'),
					createEndpoint: articleMappingUrl,
					taxListEndpoint: taxListUrl,
					lang: {
						createNew: lang('createNewArticle'),
						backToSearch: lang('backToSearch'),
						articleCode: lang('articleCode'),
						defaultPrice: lang('defaultPrice'),
						creationFailed: lang('creationFailed'),
						name: lang('name'),
						unit: lang('unit'),
						taxCode: lang('taxCode'),
						save: lang('save'),
						cancel: lang('cancel'),
						usesFallback: lang('usesFallback')
					}
				}
			);
		}

		document.getElementById('modal-article-submit').addEventListener('click', function () {
			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			function resetBtn() {
				btn.disabled = false;
				btn.textContent = lang('save');
			}

			// If in create mode, create the article first, then save
			var getMappingId;
			if (!isEdit && articleSelector && articleSelector.inCreateMode) {
				getMappingId = articleSelector.submitCreate();
			} else if (!isEdit) {
				var mappingId = articleSelector.getValue();
				if (!mappingId) { articleSelector.input.focus(); resetBtn(); return; }
				getMappingId = Promise.resolve(mappingId);
			} else {
				getMappingId = Promise.resolve(null);
			}

			getMappingId.then(function (mappingId) {
				// If article details section has changes, save those first
				var detailPromise = Promise.resolve();
				if (isEdit && document.getElementById('article-details-body') && !document.getElementById('article-details-body').hidden) {
					var detailData = {};
					var hasDetailChanges = false;

					// Name (MLT)
					if (detailNameMlt) {
						var nameValues = detailNameMlt.getValue();
						var origValues = article.service_name_json || {};
						if (JSON.stringify(nameValues) !== JSON.stringify(origValues)) {
							detailData.name_json = nameValues;
							// Use first non-empty value as plain name
							var langOrder = ['no', 'en', 'nn'];
							for (var li = 0; li < langOrder.length; li++) {
								if (nameValues[langOrder[li]] && nameValues[langOrder[li]].trim()) {
									detailData.name = nameValues[langOrder[li]].trim();
									break;
								}
							}
							hasDetailChanges = true;
						}
					}

					// Article code
					var codeVal = document.getElementById('modal-detail-code').value.trim();
					if (codeVal !== (article.article_code || '')) {
						detailData.article_code = codeVal;
						hasDetailChanges = true;
					}

					// Unit
					var unitVal = document.getElementById('modal-detail-unit').value;
					if (unitVal !== article.unit) {
						detailData.unit = unitVal;
						hasDetailChanges = true;
					}

					// Tax code
					if (detailTaxSelector) {
						var taxVal = detailTaxSelector.getValue();
						if (taxVal && parseInt(taxVal, 10) !== article.base_tax_code) {
							detailData.tax_code = parseInt(taxVal, 10);
							hasDetailChanges = true;
						}
					}

					// Base price
					var basePriceVal = document.getElementById('modal-detail-base-price').value;
					var origBasePrice = article.base_price != null ? String(article.base_price) : '';
					if (basePriceVal !== origBasePrice) {
						detailData.price = basePriceVal !== '' ? parseFloat(basePriceVal) : null;
						hasDetailChanges = true;
					}

					if (hasDetailChanges && articleMappingUrl) {
						detailPromise = putJson(articleMappingUrl + '/' + article.article_mapping_id, detailData);
					}
				}

				return detailPromise.then(function () {
					var data = {};
					if (!isEdit) {
						data.article_mapping_id = mappingId;
					}

					var groupVal = groupSelector.getValue();
					data.article_group_id = groupVal ? parseInt(groupVal, 10) : null;

					var priceVal = document.getElementById('modal-article-price').value;
					data.override_price = priceVal !== '' ? parseFloat(priceVal) : null;

					data.sort_order = parseInt(document.getElementById('modal-article-sort').value, 10) || 0;
					data.active = document.getElementById('modal-article-active').checked ? 1 : 0;

					// Multi-language description
					if (descMlt) data.description = descMlt.getValue();

					var promise = isEdit
						? putJson(apiUrl + '/articles/' + article.id, data)
						: postJson(apiUrl + '/articles', data);

					return promise;
				});
			}).then(function () {
				closeModal('article-dialog');
				showToast(lang('saved'));
				refreshData();
			}).catch(function (err) {
				resetBtn();
				showToast(lang('error') + ': ' + err.message, 'danger');
			});
		});
	}

	// ═══════════════════════════════════════════════════════════════════
	// Orders tab
	// ═══════════════════════════════════════════════════════════════════

	function renderOrders(orders) {
		var html = '';

		if (!orders || orders.length === 0) {
			html = '<p class="app-show__empty">' + esc(lang('noOrders')) + '</p>';
			document.getElementById('hospitality-orders').innerHTML = html;
			return;
		}

		var statusColorMap = {
			pending: 'warning',
			confirmed: 'info',
			delivered: 'success',
			cancelled: 'danger'
		};

		html += '<table class="ds-table" data-border>' +
			'<thead><tr>' +
			'<th>ID</th>' +
			'<th>' + lang('application') + '</th>' +
			'<th>' + lang('status') + '</th>' +
			'<th>' + lang('location') + '</th>' +
			'<th>' + lang('amount') + '</th>' +
			'<th>' + lang('created') + '</th>' +
			'</tr></thead><tbody>';

		orders.forEach(function (order) {
			var statusColor = statusColorMap[(order.status || '').toLowerCase()] || 'neutral';
			var statusTag = '<span class="ds-tag" data-color="' + statusColor + '">' + esc(order.status) + '</span>';
			var amount = order.total_amount != null ? Number(order.total_amount).toFixed(2) : '&mdash;';

			html += '<tr class="hosp-show__order-toggle" data-order-toggle="' + order.id + '">';
			html += '<td>' + esc(order.id) + '</td>';
			html += '<td>#' + esc(order.application_id) + '</td>';
			html += '<td>' + statusTag + '</td>';
			html += '<td>' + esc(order.location_name) + '</td>';
			html += '<td>' + amount + '</td>';
			html += '<td>' + fmtDate(order.created) + '</td>';
			html += '</tr>';

			// Expandable detail row
			var lines = order.lines || [];
			html += '<tr class="hosp-show__order-detail" data-order-detail="' + order.id + '" hidden>';
			html += '<td colspan="6">';

			if (order.comment) {
				html += '<p><strong>' + esc(lang('comment')) + ':</strong> ' + esc(order.comment) + '</p>';
			}

			if (lines.length > 0) {
				html += '<table class="ds-table" data-border>' +
					'<thead><tr><th>' + lang('article') + '</th><th>' + lang('unit') + '</th><th>' + lang('price') + '</th><th>' + lang('quantity') + '</th><th>' + lang('amount') + '</th></tr></thead><tbody>';
				lines.forEach(function (line) {
					var lineAmount = line.amount != null ? Number(line.amount).toFixed(2) : '&mdash;';
					html += '<tr><td>' + esc(line.article_name) + '</td><td>' + esc(line.unit) + '</td><td>' + Number(line.unit_price || 0).toFixed(2) + '</td><td>' + (line.quantity || 0) + '</td><td>' + lineAmount + '</td></tr>';
				});
				html += '</tbody></table>';
			}

			html += '</td></tr>';
		});

		html += '</tbody></table>';

		document.getElementById('hospitality-orders').innerHTML = html;
	}

	// Order row expand/collapse
	root.addEventListener('click', function (e) {
		var row = e.target.closest('[data-order-toggle]');
		if (!row) return;
		var orderId = row.dataset.orderToggle;
		var detailRow = root.querySelector('[data-order-detail="' + orderId + '"]');
		if (detailRow) detailRow.hidden = !detailRow.hidden;
	});

	// ═══════════════════════════════════════════════════════════════════
	// Modal system (reused from application_show pattern)
	// ═══════════════════════════════════════════════════════════════════

	function showModal(id, title, bodyHtml, footerHtml) {
		var existing = document.getElementById(id);
		if (existing) existing.remove();

		var modal = document.createElement('div');
		modal.id = id;
		modal.className = 'app-modal';
		modal.innerHTML =
			'<div class="app-modal-dialog app-modal-dialog-centered">' +
			'<div class="app-modal-content">' +
			'<div class="app-modal-header">' +
			'<h3 style="margin:0">' + esc(title) + '</h3>' +
			'<button type="button" class="app-btn-close" data-modal-close>&times;</button>' +
			'</div>' +
			'<div class="app-modal-body">' + bodyHtml + '</div>' +
			'<div class="app-modal-footer">' + footerHtml + '</div>' +
			'</div></div>';

		document.body.appendChild(modal);

		requestAnimationFrame(function () {
			modal.classList.add('show');
		});

		modal.addEventListener('click', function (e) {
			if (e.target.closest('[data-modal-close]')) {
				closeModal(id);
			}
		});
		modal.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') closeModal(id);
		});

		var firstInput = modal.querySelector('textarea, select, input');
		if (firstInput) setTimeout(function () { firstInput.focus(); }, 50);

		return modal;
	}

	function closeModal(id) {
		var modal = document.getElementById(id);
		if (modal) {
			modal.classList.remove('show');
			setTimeout(function () { modal.remove(); }, 200);
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// Collaborative editing UI
	// ═══════════════════════════════════════════════════════════════════

	var COLLAB_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
	var peerColorMap = {};

	function getPeerColor(peerId) {
		if (!peerColorMap[peerId]) {
			var idx = Object.keys(peerColorMap).length % COLLAB_COLORS.length;
			peerColorMap[peerId] = COLLAB_COLORS[idx];
		}
		return peerColorMap[peerId];
	}

	function renderPresence(peers) {
		var bar = document.getElementById('collab-presence-bar');
		if (!bar) return;

		if (!peers || peers.length === 0) {
			bar.innerHTML = '';
			return;
		}

		var html = '<div class="collab-presence">' +
			'<span class="collab-presence__label">' + esc(lang('viewers')) + ':</span>' +
			'<span class="collab-presence__peers">';

		peers.forEach(function (p) {
			var color = getPeerColor(p.peerId);
			html += '<span class="collab-presence__peer">' +
				'<span class="collab-presence__dot" style="background:' + color + '"></span>' +
				'<span class="collab-presence__name">' + esc(p.userName) + '</span>' +
				'</span>';
		});

		html += '</span></div>';
		bar.innerHTML = html;
	}

	function showEditIndicator(peerId, userName, scope) {
		// scope format: "field:name", "article:42", "drag:articles"
		var parts = scope.split(':');
		var type = parts[0];
		var key = parts[1];
		var color = getPeerColor(peerId);
		var badgeId = 'collab-badge-' + peerId + '-' + scope.replace(/[^a-zA-Z0-9]/g, '_');

		var targetEl = null;
		if (type === 'field') {
			targetEl = root.querySelector('[data-editable="' + key + '"]');
		} else if (type === 'article') {
			targetEl = root.querySelector('[data-edit-article="' + key + '"]');
			if (targetEl) targetEl = targetEl.closest('.hosp-show__article-row');
		} else if (type === 'drag') {
			targetEl = root.querySelector('[data-dnd-' + key + ']') || root.querySelector('#hospitality-articles');
		}

		if (!targetEl) return;

		targetEl.classList.add('collab-editing');
		targetEl.style.setProperty('--collab-color', color);

		// Add name badge if not already present
		if (!document.getElementById(badgeId)) {
			var badge = document.createElement('span');
			badge.id = badgeId;
			badge.className = 'collab-editing__badge';
			badge.style.background = color;
			badge.textContent = userName;
			targetEl.style.position = 'relative';
			targetEl.appendChild(badge);
		}
	}

	function removeEditIndicator(peerId, scope) {
		var badgeId = 'collab-badge-' + peerId + '-' + scope.replace(/[^a-zA-Z0-9]/g, '_');
		var badge = document.getElementById(badgeId);
		if (badge) {
			var parent = badge.parentElement;
			badge.remove();
			// Only remove collab-editing class if no more badges remain
			if (parent && !parent.querySelector('.collab-editing__badge')) {
				parent.classList.remove('collab-editing');
				parent.style.removeProperty('--collab-color');
			}
		}
	}

	function showConnectionStatus(connected) {
		var bar = document.getElementById('collab-presence-bar');
		if (!bar) return;

		var existing = bar.querySelector('.collab-status');
		if (connected) {
			if (existing) existing.remove();
		} else {
			if (!existing) {
				var el = document.createElement('div');
				el.className = 'collab-status collab-status--reconnecting';
				el.textContent = lang('reconnecting') + '...';
				bar.insertBefore(el, bar.firstChild);
			}
		}
	}

	function showDeletedBanner() {
		var bar = document.getElementById('collab-presence-bar');
		if (!bar) return;
		bar.innerHTML = '<div class="collab-deleted-banner">' + esc(lang('entityDeleted')) + '</div>';

		// Disable all interactive elements
		root.querySelectorAll('button, input, select, textarea').forEach(function (el) {
			el.disabled = true;
		});
	}

	function showConflictDialog(currentData, retryFn) {
		var body = '<p>' + esc(lang('conflictMessage')) + '</p>';
		if (currentData) {
			body += '<div class="collab-conflict__current">' +
				'<strong>' + esc(lang('serverVersion')) + ':</strong><br>' +
				esc(JSON.stringify(currentData, null, 2).substring(0, 500)) +
				'</div>';
		}

		var footer = '<button type="button" class="app-button" data-modal-close id="conflict-keep-theirs">' + esc(lang('keepTheirs')) + '</button>' +
			'<button type="button" class="app-button app-button-primary" id="conflict-overwrite">' + esc(lang('overwriteWithMine')) + '</button>';

		showModal('collab-conflict-dialog', lang('editConflict'), body, footer);

		document.getElementById('conflict-keep-theirs').addEventListener('click', function () {
			// Accept server version — refresh
			closeModal('collab-conflict-dialog');
			refreshData();
		});

		document.getElementById('conflict-overwrite').addEventListener('click', function () {
			closeModal('collab-conflict-dialog');
			if (retryFn) retryFn();
		});
	}

	// ═══════════════════════════════════════════════════════════════════
	// WebSocket collab hook (consumed by ES module script)
	// ═══════════════════════════════════════════════════════════════════

	window.__hospWs = {
		renderPresence: renderPresence,
		showEditIndicator: showEditIndicator,
		removeEditIndicator: removeEditIndicator,
		showConnectionStatus: showConnectionStatus,
		showDeletedBanner: showDeletedBanner,
		showConflictDialog: showConflictDialog,
		refreshSection: refreshSection,
		refreshData: refreshData,
		renderHeader: renderHeader,
		renderDetails: renderDetails,
		renderResources: renderResources,
		renderArticles: renderArticles,
		putJson: putJson,
		showToast: showToast,
		lang: lang,
		esc: esc,
		root: root,
		getHospitalityData: function () { return hospitalityData; },
		collab: null  // Set by module script after CollabClient is created
	};

	// ═══════════════════════════════════════════════════════════════════
	// DnD integration hook (consumed by ES module script)
	// ═══════════════════════════════════════════════════════════════════

	window.__hospDnd = {
		getApiUrl: function () { return apiUrl; },
		canWrite: function () { return canWrite; },
		getData: function () { return hospitalityData; },
		putJson: putJson,
		refreshData: refreshData,
		showToast: showToast,
		lang: lang,
		root: root
	};

})();
