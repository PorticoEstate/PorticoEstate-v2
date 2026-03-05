(function () {
	'use strict';

	var root = document.getElementById('hospitality-show');
	if (!root) return;

	var apiUrl = root.dataset.apiUrl.split('?')[0];
	var ordersUrl = root.dataset.ordersUrl.split('?')[0] + '?hospitality_id=' + root.dataset.hospitalityId;
	var resourcesApiUrl = root.dataset.resourcesUrl.split('?')[0];
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

	function sendJson(method, url, data) {
		return fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(data || {})
		}).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) throw new Error(json.error || 'HTTP ' + res.status);
				return json;
			});
		});
	}

	function putJson(url, data) { return sendJson('PUT', url, data); }
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

			putJson(apiUrl, payload).then(function (updated) {
				hospitalityData = Object.assign(hospitalityData, updated);
				showToast(lang('saved'));
				renderDetails(hospitalityData);
				renderHeader(hospitalityData);
			}).catch(function (err) {
				showToast(lang('error') + ': ' + err.message, 'danger');
				renderDetails(hospitalityData);
			});
		});

		// Cancel handler
		valueSpan.querySelector('.hosp-show__edit-cancel').addEventListener('click', function () {
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

	function renderResources(h) {
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

		// Remote locations
		var locations = h.remote_locations || [];
		var locHtml = '';

		if (locations.length > 0) {
			locHtml += '<table class="ds-table" data-border>' +
				'<thead><tr><th>' + lang('resource') + '</th><th>' + lang('active') + '</th>';
			if (canWrite) locHtml += '<th></th>';
			locHtml += '</tr></thead><tbody>';

			locations.forEach(function (loc) {
				locHtml += '<tr><td>' + esc(loc.resource_name) + '</td>';
				if (canWrite) {
					locHtml += '<td><label class="hosp-show__toggle">' +
						'<input type="checkbox" data-toggle-resource="' + loc.resource_id + '"' + (loc.active ? ' checked' : '') + '>' +
						'<span class="hosp-show__toggle-slider"></span></label></td>';
					locHtml += '<td><button type="button" class="app-button app-button-sm app-button-danger" data-remove-resource="' + loc.resource_id + '">' + trashIcon + '</button></td>';
				} else {
					locHtml += '<td>' + (loc.active ? lang('yes') : lang('no')) + '</td>';
				}
				locHtml += '</tr>';
			});

			locHtml += '</tbody></table>';
		} else {
			locHtml = '<p class="app-show__empty">' + esc(lang('noRemoteLocations')) + '</p>';
		}

		if (canWrite) {
			var addBtn = '<button type="button" class="app-button app-button-sm" id="add-remote-location-btn">' + esc(lang('add')) + '</button>';
			html += section(lang('remoteLocations'), locHtml, { headerHtml: addBtn });
		} else {
			html += section(lang('remoteLocations'), locHtml);
		}

		document.getElementById('hospitality-resources').innerHTML = html;
	}

	// Toggle remote location active
	root.addEventListener('change', function (e) {
		var toggle = e.target.closest('[data-toggle-resource]');
		if (!toggle) return;
		var resourceId = toggle.dataset.toggleResource;
		patchJson(apiUrl + '/remote-locations/' + resourceId, { active: toggle.checked }).then(function () {
			showToast(lang('saved'));
			refreshData();
		}).catch(function (err) {
			showToast(lang('error') + ': ' + err.message, 'danger');
			toggle.checked = !toggle.checked;
		});
	});

	// Remove remote location
	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-remove-resource]');
		if (!btn) return;
		if (!confirm(lang('confirmDelete'))) return;
		var resourceId = btn.dataset.removeResource;
		btn.disabled = true;
		deleteJson(apiUrl + '/remote-locations/' + resourceId).then(function () {
			showToast(lang('saved'));
			refreshData();
		}).catch(function (err) {
			btn.disabled = false;
			showToast(lang('error') + ': ' + err.message, 'danger');
		});
	});

	// Add remote location button
	root.addEventListener('click', function (e) {
		if (!e.target.closest('#add-remote-location-btn')) return;
		showResourcePickerModal();
	});

	function showResourcePickerModal() {
		var body = '<p>' + esc(lang('selectResource')) + '</p>' +
			'<select id="modal-resource-select" class="app-show__modal-select">' +
			'<option value="">' + esc(lang('loading')) + '...</option></select>';
		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-primary" id="modal-resource-submit" disabled>' + esc(lang('add')) + '</button>';

		showModal('resource-picker-dialog', lang('add') + ' ' + lang('resource'), body, footer);

		var select = document.getElementById('modal-resource-select');
		var submitBtn = document.getElementById('modal-resource-submit');

		// Fetch all resources and filter out ones already added
		fetchJson(resourcesApiUrl).then(function (data) {
			var resources = Array.isArray(data) ? data : (data.results || []);
			var existingIds = (hospitalityData.remote_locations || []).map(function (l) { return l.resource_id; });
			existingIds.push(hospitalityData.resource_id); // exclude main resource
			var available = resources.filter(function (r) {
				return existingIds.indexOf(r.id) === -1;
			});

			select.innerHTML = '<option value="">-- ' + esc(lang('selectResource')) + ' --</option>';
			available.forEach(function (r) {
				select.innerHTML += '<option value="' + r.id + '">' + esc(r.name) + '</option>';
			});
			submitBtn.disabled = false;
		}).catch(function () {
			select.innerHTML = '<option value="">' + esc(lang('error')) + '</option>';
		});

		submitBtn.addEventListener('click', function () {
			var resourceId = parseInt(select.value, 10);
			if (!resourceId) return;
			submitBtn.disabled = true;
			submitBtn.textContent = '...';

			postJson(apiUrl + '/remote-locations', { resource_id: resourceId }).then(function () {
				closeModal('resource-picker-dialog');
				showToast(lang('saved'));
				refreshData();
			}).catch(function (err) {
				submitBtn.disabled = false;
				submitBtn.textContent = lang('add');
				showToast(lang('error') + ': ' + err.message, 'danger');
			});
		});
	}

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
				html += '<div class="hosp-show__group-actions">' +
					'<button type="button" class="app-button app-button-sm" data-edit-group="' + group.id + '" title="' + esc(lang('edit')) + '">' + penIcon + '</button>' +
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

		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-primary" id="modal-article-submit">' + esc(lang('save')) + '</button>';

		showModal('article-dialog', title, body, footer);

		// Initialize group search select
		var groupSelector = new SearchSelect(
			document.getElementById('modal-group-select-container'),
			{
				items: groups,
				allowEmpty: true,
				emptyLabel: '-- ' + lang('ungroupedArticles') + ' --',
				value: isEdit ? (article.article_group_id || null) : null
			}
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
					emptyText: lang('noArticles')
				}
			);
		}

		document.getElementById('modal-article-submit').addEventListener('click', function () {
			var data = {};

			if (!isEdit) {
				var mappingId = articleSelector.getValue();
				if (!mappingId) { articleSelector.input.focus(); return; }
				data.article_mapping_id = mappingId;
			}

			var groupVal = groupSelector.getValue();
			data.article_group_id = groupVal ? parseInt(groupVal, 10) : null;

			var priceVal = document.getElementById('modal-article-price').value;
			data.override_price = priceVal !== '' ? parseFloat(priceVal) : null;

			data.sort_order = parseInt(document.getElementById('modal-article-sort').value, 10) || 0;
			data.active = document.getElementById('modal-article-active').checked ? 1 : 0;

			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			var promise = isEdit
				? putJson(apiUrl + '/articles/' + article.id, data)
				: postJson(apiUrl + '/articles', data);

			promise.then(function () {
				closeModal('article-dialog');
				showToast(lang('saved'));
				refreshData();
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('save');
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
			if (e.target === modal || e.target.closest('[data-modal-close]')) {
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
