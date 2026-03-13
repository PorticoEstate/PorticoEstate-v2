(function () {
	'use strict';

	var root = document.getElementById('order-show');
	if (!root) return;

	var orderUrl = root.dataset.orderUrl.split('?')[0];
	var ordersBaseUrl = root.dataset.ordersBaseUrl.split('?')[0];
	var hospitalityBaseUrl = root.dataset.hospitalityBaseUrl.split('?')[0];
	var applicationsBaseUrl = root.dataset.applicationsBaseUrl ? root.dataset.applicationsBaseUrl.split('?')[0] : null;
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
		toast.className = 'ds-alert app-show__toast';
		toast.dataset.color = type || 'success';
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

	// ── Serving time helpers ──

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

	function fmtShortDate(d) {
		return d.toLocaleDateString('nb-NO', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
	}

	function fmtTimeHHMM(d) {
		return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
	}

	function fmtNaiveIso(d) {
		return d.getFullYear() + '-' +
			String(d.getMonth() + 1).padStart(2, '0') + '-' +
			String(d.getDate()).padStart(2, '0') + 'T' +
			String(d.getHours()).padStart(2, '0') + ':' +
			String(d.getMinutes()).padStart(2, '0') + ':00';
	}

	function populateEditTimeSlots(timeEl, dateIdx, currentIso) {
		timeEl.innerHTML = '<option value="">' + esc(lang('selectTime')) + '</option>';
		timeEl.disabled = true;
		if (dateIdx === '' || !appDatesData[dateIdx]) return;

		var range = appDatesData[dateIdx];
		var slots = generate15MinIntervals(range.from_, range.to_);
		var currentTs = currentIso ? new Date(currentIso).getTime() : null;
		slots.forEach(function (slot) {
			var opt = document.createElement('option');
			opt.value = fmtNaiveIso(slot);
			opt.textContent = fmtTimeHHMM(slot);
			if (currentTs && Math.abs(slot.getTime() - currentTs) < 60000) opt.selected = true;
			timeEl.appendChild(opt);
		});
		timeEl.disabled = false;
	}

	var penIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.85 0 014 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
	var backArrow = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>';
	var commentIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>';

	// ═══════════════════════════════════════════════════════════════════
	// State
	// ═══════════════════════════════════════════════════════════════════

	var orderData = null;
	var hospitalityMainResourceId = null;
	var articleGroupsData = [];
	var allArticlesFlat = [];
	var appDatesData = [];
	var relatedAppIds = [];  // sibling application IDs (excluding the order's own)

	// Edit mode state
	var editMode = false;
	var pendingChangelogComment = null;
	var pendingLineChanges = {};
	// Shape: { [articleId]: { quantity: number, comment: string|null, lineId: number|null, unitPrice: number } }

	function hasPendingLineChanges() {
		return Object.keys(pendingLineChanges).length > 0;
	}

	var TERMINAL_STATUSES = ['cancelled', 'delivered'];
	var STATUS_COLOR_MAP = {
		pending: 'warning',
		confirmed: 'info',
		delivered: 'success',
		cancelled: 'danger'
	};
	var STATUS_LABEL_MAP = {
		pending: 'pendingStatus',
		confirmed: 'confirmed',
		delivered: 'delivered',
		cancelled: 'cancelled'
	};

	function isTerminal() {
		return orderData && TERMINAL_STATUSES.indexOf(orderData.status) !== -1;
	}

	// ═══════════════════════════════════════════════════════════════════
	// Modal system
	// ═══════════════════════════════════════════════════════════════════

	function showModal(id, title, bodyHtml, footerHtml) {
		var existing = document.getElementById(id);
		if (existing) existing.remove();

		var dialog = document.createElement('dialog');
		dialog.id = id;
		dialog.className = 'ds-dialog';
		dialog.innerHTML =
			'<div class="ds-dialog__block" style="display:flex;align-items:center;justify-content:space-between">' +
			'<h3 style="margin:0">' + esc(title) + '</h3>' +
			'<button type="button" class="ds-button" data-variant="tertiary" data-icon data-modal-close aria-label="Lukk">&times;</button>' +
			'</div>' +
			'<div class="ds-dialog__block">' + bodyHtml + '</div>' +
			'<div class="ds-dialog__block" style="display:flex;justify-content:flex-end;gap:0.5rem">' + footerHtml + '</div>';

		document.body.appendChild(dialog);
		dialog.showModal();

		dialog.addEventListener('click', function (e) {
			if (e.target.closest('[data-modal-close]')) {
				closeModal(id);
			} else if (e.target === dialog) {
				closeModal(id);
			}
		});

		dialog.addEventListener('close', function () {
			dialog.remove();
		});

		var firstInput = dialog.querySelector('textarea, select, input');
		if (firstInput) setTimeout(function () { firstInput.focus(); }, 50);

		return dialog;
	}

	function closeModal(id) {
		var dialog = document.getElementById(id);
		if (dialog) {
			dialog.close();
			dialog.remove();
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// Changelog comment prompt
	// ═══════════════════════════════════════════════════════════════════

	function ensureChangelogComment() {
		return new Promise(function (resolve, reject) {
			if (pendingChangelogComment) {
				resolve(pendingChangelogComment);
				return;
			}

			var body = '<label class="app-show__modal-label" for="changelog-comment-input">' +
				esc(lang('changelogCommentRequired')) + '</label>' +
				'<textarea id="changelog-comment-input" class="app-show__modal-textarea" rows="3" placeholder="' +
				esc(lang('changelogCommentPlaceholder')) + '"></textarea>';

			var footer = '<button type="button" class="ds-button" data-variant="secondary" data-modal-close>' + esc(lang('cancel')) + '</button>' +
				'<button type="button" class="ds-button" id="changelog-comment-submit">' + esc(lang('save')) + '</button>';

			showModal('changelog-comment-dialog', lang('changelogComment'), body, footer);

			document.getElementById('changelog-comment-submit').addEventListener('click', function () {
				var comment = document.getElementById('changelog-comment-input').value.trim();
				if (!comment) {
					document.getElementById('changelog-comment-input').focus();
					return;
				}
				pendingChangelogComment = comment;
				closeModal('changelog-comment-dialog');
				resolve(comment);
			});

			// If user closes modal without commenting, reject
			var modal = document.getElementById('changelog-comment-dialog');
			var observer = new MutationObserver(function () {
				if (!document.getElementById('changelog-comment-dialog')) {
					observer.disconnect();
					if (!pendingChangelogComment) {
						reject(new Error('cancelled'));
					}
				}
			});
			observer.observe(document.body, { childList: true });
		});
	}

	// ═══════════════════════════════════════════════════════════════════
	// Data fetching & initialization
	// ═══════════════════════════════════════════════════════════════════

	fetchJson(orderUrl).then(function (order) {
		orderData = order;

		var hospUrl = hospitalityBaseUrl + '/' + order.hospitality_id;
		var groupsUrl = hospUrl + '/article-groups';
		var articlesUrl = hospUrl + '/articles';
		var datesUrl = applicationsBaseUrl ? applicationsBaseUrl + '/' + order.application_id + '/dates' : null;

		var relatedUrl = applicationsBaseUrl ? applicationsBaseUrl + '/' + order.application_id + '/related' : null;

		var fetches = [fetchJson(groupsUrl), fetchJson(articlesUrl), fetchJson(hospUrl)];
		if (datesUrl) fetches.push(fetchJson(datesUrl).catch(function () { return []; }));
		// index 4: related apps (may be empty array for standalone apps)
		if (relatedUrl) fetches.push(fetchJson(relatedUrl).catch(function () { return []; }));

		return Promise.all(fetches).then(function (results) {
			articleGroupsData = results[0];
			allArticlesFlat = results[1];
			hospitalityMainResourceId = results[2].resource_id;

			var allDates = results[3] || [];
			var locResId = order.location_resource_id;
			if (locResId === hospitalityMainResourceId) {
				appDatesData = allDates;
			} else {
				appDatesData = allDates.filter(function (d) {
					return d.resources && d.resources.indexOf(locResId) !== -1;
				});
			}

			// Related apps: filter out the order's own application_id
			var relatedApps = results[4] || [];
			relatedAppIds = relatedApps
				.map(function (a) { return a.id; })
				.filter(function (id) { return id !== order.application_id; });

			render();
		});
	}).catch(function (err) {
		document.getElementById('order-loading').hidden = true;
		var errEl = document.getElementById('order-error');
		errEl.hidden = false;
		document.getElementById('order-error-message').textContent =
			lang('error') + ': ' + err.message;
	});

	function refreshOrder() {
		return fetchJson(orderUrl).then(function (order) {
			orderData = order;
			renderHeader();
			renderDetails();
			renderLines();
			renderChangelog();
		}).catch(function (err) {
			showToast(lang('error') + ': ' + err.message, 'danger');
		});
	}

	// ═══════════════════════════════════════════════════════════════════
	// Main render
	// ═══════════════════════════════════════════════════════════════════

	function render() {
		document.getElementById('order-loading').hidden = true;
		document.getElementById('order-content').hidden = false;

		renderHeader();
		renderDetails();
		renderLines();
		renderChangelog();
	}

	// ═══════════════════════════════════════════════════════════════════
	// Header
	// ═══════════════════════════════════════════════════════════════════

	function renderHeader() {
		var o = orderData;
		var statusKey = STATUS_LABEL_MAP[o.status] || o.status;
		var statusColor = STATUS_COLOR_MAP[o.status] || 'neutral';
		var statusTag = '<span class="ds-tag" data-color="' + statusColor + '">' + esc(lang(statusKey)) + '</span>';

		var backUrl = '/booking/view/hospitality/' + o.hospitality_id + '#orders';
		var html = '<a href="' + backUrl + '" class="hosp-show__back-link">' +
			backArrow + ' ' + esc(lang('backToHospitality')) + '</a>';

		html += '<div class="app-show__title-row">' +
			'<div class="app-show__title-left">' +
			'<h1 class="app-show__title">' + lang('orders') + ' #' + esc(o.id) + '</h1>' +
			statusTag +
			'</div>';

		// Edit mode toggle button + save
		if (canWrite && !isTerminal()) {
			if (editMode) {
				html += '<div class="order-show__edit-actions">' +
					'<button type="button" class="ds-button" id="save-all-changes">' +
					esc(lang('save')) + '</button>' +
					'<button type="button" class="ds-button order-show__edit-toggle order-show__edit-toggle--active" data-variant="secondary" id="toggle-edit-mode">' +
					esc(lang('exitEditMode')) + '</button>' +
					'</div>';
			} else {
				html += '<button type="button" class="ds-button order-show__edit-toggle" id="toggle-edit-mode">' +
					penIcon + ' ' + esc(lang('edit')) + '</button>';
			}
		}

		html += '</div>';

		html += '<div class="app-show__meta">';
		if (o.hospitality_name) {
			html += '<span class="app-show__meta-item">' + lang('hospitality') + ': ' + esc(o.hospitality_name) + '</span>';
		}
		html += '<span class="app-show__meta-item">' + lang('created') + ': ' + fmtDate(o.created) + '</span>';
		if (o.modified) {
			html += '<span class="app-show__meta-item">' + lang('modified') + ': ' + fmtDate(o.modified) + '</span>';
		}
		html += '</div>';

		document.getElementById('order-header').innerHTML = html;
	}

	// Edit mode toggle handler
	root.addEventListener('click', function (e) {
		if (!e.target.closest('#toggle-edit-mode')) return;

		editMode = !editMode;
		if (!editMode) {
			pendingChangelogComment = null;
			pendingLineChanges = {};
		}
		renderHeader();
		renderDetails();
		renderLines();
	});

	// ═══════════════════════════════════════════════════════════════════
	// Details section
	// ═══════════════════════════════════════════════════════════════════

	function renderDetails() {
		var o = orderData;
		var html = '';

		var detailsHtml = '';

		// Application link (+ related app IDs if combined)
		if (o.application_id) {
			var appValueHtml = '<a href="/booking/view/applications/' + o.application_id + '">#' + esc(o.application_id) + '</a>';
			if (relatedAppIds.length > 0) {
				var relatedText = relatedAppIds.map(function (id) { return '#' + esc(id); }).join(', ');
				appValueHtml += ' <span class="order-show__related-apps">(' + relatedText + ')</span>';
			}
			detailsHtml += fieldHtml(lang('application'), appValueHtml);
		}

		detailsHtml += field(lang('location'), o.location_name);
		detailsHtml += editableField(lang('servingTime'), o.serving_time_iso ? fmtDate(o.serving_time_iso) : null, 'serving_time_iso', 'datetime');
		detailsHtml += field(lang('created'), fmtDate(o.created));
		detailsHtml += field(lang('modified'), fmtDate(o.modified));

		// Editable fields
		detailsHtml += editableField(lang('comment'), o.comment, 'comment', 'textarea');
		detailsHtml += editableField(lang('specialRequirements'), o.special_requirements, 'special_requirements', 'textarea');

		html += section(lang('details'), detailsHtml);

		// Status actions — visible when NOT in edit mode (separate from field editing)
		if (canWrite && !editMode && !isTerminal()) {
			var actionsHtml = '<div class="order-show__status-actions">';

			if (o.status === 'pending') {
				actionsHtml += '<button type="button" class="ds-button" data-status-action="confirmed">' +
					esc(lang('confirmOrder')) + '</button>';
				actionsHtml += '<button type="button" class="ds-button" data-color="danger" data-status-action="cancelled">' +
					esc(lang('cancelOrder')) + '</button>';
			} else if (o.status === 'confirmed') {
				actionsHtml += '<button type="button" class="ds-button" data-status-action="delivered">' +
					esc(lang('deliverOrder')) + '</button>';
				actionsHtml += '<button type="button" class="ds-button" data-color="danger" data-status-action="cancelled">' +
					esc(lang('cancelOrder')) + '</button>';
			}

			actionsHtml += '</div>';
			html += actionsHtml;
		}

		document.getElementById('order-details').innerHTML = html;

		if (editMode) {
			wireEditableDatetimes();
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// Unified save — details + line changes
	// ═══════════════════════════════════════════════════════════════════

	function collectDetailChanges() {
		var payload = {};
		var fields = root.querySelectorAll('[data-edit-field]');
		fields.forEach(function (el) {
			var fieldName = el.dataset.editField;
			var fieldType = el.dataset.fieldType;
			var newValue;

			if (fieldType === 'datetime') {
				var dateEl = el.querySelector('[data-edit-date]');
				var timeEl = el.querySelector('[data-edit-time]');
				newValue = timeEl.value || null;
				var selectedDateIdx = dateEl.value;
				if (selectedDateIdx !== '' && appDatesData[selectedDateIdx]) {
					payload.application_id = appDatesData[selectedDateIdx].application_id;
				}
			} else if (el.tagName === 'TEXTAREA') {
				newValue = el.value;
			} else if (el.tagName === 'INPUT') {
				newValue = el.value;
			}

			var currentValue = orderData[fieldName] != null ? String(orderData[fieldName]) : '';
			var newStr = newValue != null ? String(newValue) : '';
			if (newStr !== currentValue) {
				payload[fieldName] = newValue;
			}
		});
		return payload;
	}

	root.addEventListener('click', function (e) {
		var btn = e.target.closest('#save-all-changes');
		if (!btn) return;

		var detailPayload = collectDetailChanges();
		var hasDetailChanges = Object.keys(detailPayload).length > 0;
		var hasLineChanges = hasPendingLineChanges();

		if (!hasDetailChanges && !hasLineChanges) {
			showToast(lang('noChanges'), 'info');
			return;
		}

		ensureChangelogComment().then(function (comment) {
			btn.disabled = true;
			btn.textContent = '...';

			var chain = Promise.resolve();

			// 1. Save detail fields
			if (hasDetailChanges) {
				detailPayload.changelog_comment = comment;
				chain = chain.then(function () {
					return putJson(orderUrl, detailPayload).then(function (updatedOrder) {
						orderData = updatedOrder;
					});
				});
			}

			// 2. Save line changes
			if (hasLineChanges) {
				var orderId = orderData.id;
				Object.keys(pendingLineChanges).forEach(function (artId) {
					var p = pendingLineChanges[artId];
					artId = parseInt(artId, 10);

					if (p.quantity === 0 && p.lineId) {
						chain = chain.then(function () {
							return deleteJson(ordersBaseUrl + '/' + orderId + '/lines/' + p.lineId +
								'?changelog_comment=' + encodeURIComponent(comment)).then(function (r) { orderData = r; });
						});
					} else if (p.quantity > 0 && p.lineId) {
						chain = chain.then(function () {
							var payload = { quantity: p.quantity, changelog_comment: comment };
							if (p.comment != null) payload.comment = p.comment;
							return putJson(ordersBaseUrl + '/' + orderId + '/lines/' + p.lineId, payload).then(function (r) { orderData = r; });
						});
					} else if (p.quantity > 0 && !p.lineId) {
						chain = chain.then(function () {
							var payload = { hospitality_article_id: artId, quantity: p.quantity, changelog_comment: comment };
							if (p.comment) payload.comment = p.comment;
							return postJson(ordersBaseUrl + '/' + orderId + '/lines', payload).then(function (r) { orderData = r; });
						});
					} else if (p.lineId && p.comment !== null) {
						chain = chain.then(function () {
							return putJson(ordersBaseUrl + '/' + orderId + '/lines/' + p.lineId, {
								comment: p.comment, changelog_comment: comment
							}).then(function (r) { orderData = r; });
						});
					}
				});
			}

			chain.then(function () {
				pendingLineChanges = {};
				pendingChangelogComment = null;
				showToast(lang('saved'));
				renderHeader();
				renderDetails();
				renderLines();
				renderChangelog();
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('save');
				showToast(lang('error') + ': ' + err.message, 'danger');
			});
		}).catch(function () {
			// User cancelled
		});
	});

	// ═══════════════════════════════════════════════════════════════════
	// Inline editing (legacy pen-icon click handler — kept for safety)
	// ═══════════════════════════════════════════════════════════════════

	function editableField(label, displayValue, fieldName, fieldType) {
		var displayHtml = esc(displayValue != null ? displayValue : '');
		var currentValue = orderData[fieldName] != null ? String(orderData[fieldName]) : '';

		// Read-only when not in edit mode
		if (!canWrite || isTerminal() || !editMode) {
			return fieldHtml(label, (displayHtml || '&mdash;'));
		}

		// Edit mode — render inputs directly
		var inputHtml = '';
		if (fieldType === 'textarea') {
			inputHtml = '<textarea class="hosp-show__edit-input ds-input" data-edit-field="' + esc(fieldName) + '">' + esc(currentValue) + '</textarea>';
		} else if (fieldType === 'datetime') {
			inputHtml = '<div class="hosp-show__edit-form" data-edit-field="' + esc(fieldName) + '" data-field-type="datetime">' +
				'<select class="hosp-show__edit-input ds-select" data-edit-date>';
			inputHtml += '<option value="">' + esc(lang('selectDate')) + '</option>';
			appDatesData.forEach(function (d, i) {
				inputHtml += '<option value="' + i + '">' + esc(fmtShortDate(new Date(d.from_))) + '</option>';
			});
			inputHtml += '</select>' +
				'<select class="hosp-show__edit-input ds-select" data-edit-time disabled>' +
				'<option value="">' + esc(lang('selectTime')) + '</option>' +
				'</select></div>';
		} else {
			inputHtml = '<input type="text" class="hosp-show__edit-input ds-input" data-edit-field="' + esc(fieldName) + '" value="' + esc(currentValue) + '">';
		}

		return '<div class="app-show__field" data-editable="' + esc(fieldName) + '" data-field-type="' + esc(fieldType || 'text') + '">' +
			'<span class="app-show__label">' + esc(label) + '</span>' +
			'<span class="app-show__value">' + inputHtml + '</span></div>';
	}

	// Wire up datetime selects after rendering details
	function wireEditableDatetimes() {
		var containers = root.querySelectorAll('[data-field-type="datetime"] [data-edit-date]');
		containers.forEach(function (dateEl) {
			var wrapper = dateEl.closest('[data-edit-field]');
			if (!wrapper) return;
			var fieldName = wrapper.dataset.editField;
			var timeEl = wrapper.querySelector('[data-edit-time]');
			var currentValue = orderData[fieldName] != null ? String(orderData[fieldName]) : '';

			if (appDatesData.length === 1) {
				dateEl.value = '0';
				dateEl.disabled = true;
				populateEditTimeSlots(timeEl, 0, currentValue);
			}

			dateEl.addEventListener('change', function () {
				populateEditTimeSlots(timeEl, this.value, currentValue);
			});
		});
	}

	// ═══════════════════════════════════════════════════════════════════
	// Status transitions
	// ═══════════════════════════════════════════════════════════════════

	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-status-action]');
		if (!btn) return;

		var newStatus = btn.dataset.statusAction;

		ensureChangelogComment().then(function (comment) {
			btn.disabled = true;
			btn.textContent = '...';

			patchJson(orderUrl + '/status', { status: newStatus, changelog_comment: comment }).then(function (updatedOrder) {
				orderData = updatedOrder;
				editMode = false;
				pendingChangelogComment = null;
				showToast(lang('saved'));
				renderHeader();
				renderDetails();
				renderLines();
				renderChangelog();
			}).catch(function (err) {
				btn.disabled = false;
				showToast(lang('error') + ': ' + err.message, 'danger');
			});
		}).catch(function () {
			// User cancelled
		});
	});

	// ═══════════════════════════════════════════════════════════════════
	// Lines section (menu-style)
	// ═══════════════════════════════════════════════════════════════════

	function getArticleDescription(art) {
		var desc = art.description;
		if (!desc) return '';
		if (typeof desc === 'string') return desc;
		return desc.no || desc.en || desc.nn || '';
	}

	function renderLines() {
		var o = orderData;
		var lines = o.lines || [];
		var terminal = isTerminal();
		var editable = editMode && !terminal;

		var lineByArticle = {};
		lines.forEach(function (line) {
			lineByArticle[line.hospitality_article_id] = line;
		});

		var renderedArticleIds = {};
		var bodyHtml = '';

		articleGroupsData.forEach(function (group) {
			if (!group.active) return;
			var groupArticles = (group.articles || []).filter(function (a) { return a.active; });
			if (groupArticles.length === 0) return;

			bodyHtml += '<div class="order-show__group-header">' + esc(group.name) + '</div>';
			groupArticles.forEach(function (art) {
				renderedArticleIds[art.id] = true;
				bodyHtml += renderMenuRow(art, lineByArticle[art.id], terminal, editable);
			});
		});

		allArticlesFlat.forEach(function (art) {
			if (!art.active || art.article_group_id || renderedArticleIds[art.id]) return;
			renderedArticleIds[art.id] = true;
			bodyHtml += renderMenuRow(art, lineByArticle[art.id], terminal, editable);
		});

		lines.forEach(function (line) {
			if (renderedArticleIds[line.hospitality_article_id]) return;
			if (line.quantity > 0) {
				bodyHtml += renderOrphanLineRow(line, terminal, editable);
			}
		});

		// Total row
		var total = 0;
		lines.forEach(function (line) {
			total += Number(line.amount || 0);
		});

		bodyHtml += '<div class="order-show__total-row">';
		bodyHtml += '<span class="order-show__total-label">' + esc(lang('total')) + '</span>';
		bodyHtml += '<span class="order-show__total-amount">' + total.toFixed(2) + '</span>';
		bodyHtml += '</div>';

		var html = section(lang('orderLines'), bodyHtml);
		document.getElementById('order-lines').innerHTML = html;
	}

	function renderMenuRow(article, line, terminal, editable) {
		var qty = line ? line.quantity : 0;
		var price = Number(article.effective_price || article.override_price || article.base_price || 0);
		var amount = line ? Number(line.amount || 0) : 0;
		var dimmed = qty === 0;
		var desc = getArticleDescription(article);
		var lineComment = line ? (line.comment || '') : '';

		var html = '<div class="order-show__menu-item' + (dimmed ? ' order-show__menu-row--dimmed' : '') + '" data-article-id="' + article.id + '">';

		html += '<div class="order-show__menu-row">';
		html += '<span class="order-show__menu-name">' + esc(article.article_name || article.name);
		if (desc) html += '<span class="order-show__menu-desc">' + esc(desc) + '</span>';
		html += '</span>';
		html += '<span class="order-show__menu-unit">' + esc(article.unit) + '</span>';
		html += '<span class="order-show__menu-price">' + price.toFixed(2) + '</span>';

		if (!editable) {
			// Read-only quantity
			html += '<span class="order-show__menu-qty-readonly">' + (qty || '&mdash;') + '</span>';
		} else {
			html += '<span class="order-show__menu-qty">' +
				'<input type="number" min="0" value="' + qty + '"' +
				' data-qty-article="' + article.id + '"' +
				(line ? ' data-qty-line="' + line.id + '"' : '') +
				'>' +
				'</span>';
		}

		html += '<span class="order-show__menu-amount">' + (amount > 0 ? amount.toFixed(2) : '&mdash;') + '</span>';

		// Comment toggle button — only when editable and line exists
		var canComment = editable && qty > 0;
		html += '<button type="button" class="order-show__comment-toggle' +
			(lineComment ? ' order-show__comment-toggle--active' : '') + '"' +
			' data-toggle-comment="' + article.id + '"' +
			' title="' + esc(lang('comment')) + '"' +
			(!canComment ? ' disabled' : '') +
			'>' + commentIcon + '</button>';

		html += '</div>';

		// Comment row
		if (!editable) {
			if (lineComment) {
				html += '<div class="order-show__line-comment">' +
					'<span class="order-show__line-comment-label">' + esc(lang('comment')) + ':</span> ' +
					esc(lineComment) + '</div>';
			}
		} else if (qty > 0 || lineComment) {
			html += '<div class="order-show__line-comment"' +
				(!lineComment ? ' hidden' : '') + '>' +
				'<input type="text" class="order-show__line-comment-input" placeholder="' + esc(lang('comment')) + '..."' +
				' value="' + esc(lineComment) + '"' +
				' data-comment-article="' + article.id + '"' +
				(line ? ' data-comment-line="' + line.id + '"' : '') +
				'>' +
				'</div>';
		}

		html += '</div>';
		return html;
	}

	function renderOrphanLineRow(line, terminal, editable) {
		var qty = line.quantity || 0;
		var amount = Number(line.amount || 0);
		var lineComment = line.comment || '';

		var html = '<div class="order-show__menu-item" data-article-id="' + line.hospitality_article_id + '">';

		html += '<div class="order-show__menu-row">';
		html += '<span class="order-show__menu-name">' + esc(line.article_name || '?') + ' <em style="opacity:0.6">(removed)</em></span>';
		html += '<span class="order-show__menu-unit">' + esc(line.unit || '') + '</span>';
		html += '<span class="order-show__menu-price">' + Number(line.unit_price || 0).toFixed(2) + '</span>';

		if (!editable) {
			html += '<span class="order-show__menu-qty-readonly">' + qty + '</span>';
		} else {
			html += '<span class="order-show__menu-qty">' +
				'<input type="number" min="0" value="' + qty + '"' +
				' data-qty-article="' + line.hospitality_article_id + '"' +
				' data-qty-line="' + line.id + '">' +
				'</span>';
		}

		html += '<span class="order-show__menu-amount">' + (amount > 0 ? amount.toFixed(2) : '&mdash;') + '</span>';
		html += '</div>';

		if (lineComment) {
			html += '<div class="order-show__line-comment">' +
				'<span class="order-show__line-comment-label">' + esc(lang('comment')) + ':</span> ' +
				esc(lineComment) + '</div>';
		}

		html += '</div>';
		return html;
	}

	// ═══════════════════════════════════════════════════════════════════
	// Comment toggle button
	// ═══════════════════════════════════════════════════════════════════

	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-toggle-comment]');
		if (!btn) return;

		var item = btn.closest('.order-show__menu-item');
		if (!item) return;

		var commentRow = item.querySelector('.order-show__line-comment');
		if (!commentRow) return;

		var isHidden = commentRow.hidden;
		commentRow.hidden = !isHidden;
		btn.classList.toggle('order-show__comment-toggle--active', isHidden);

		if (isHidden) {
			var input = commentRow.querySelector('.order-show__line-comment-input');
			if (input) input.focus();
		}
	});

	// ═══════════════════════════════════════════════════════════════════
	// Pending line changes — local tracking
	// ═══════════════════════════════════════════════════════════════════

	function getUnitPrice(articleId) {
		var art = allArticlesFlat.find(function (a) { return a.id === articleId; });
		if (art) return Number(art.effective_price || art.override_price || art.base_price || 0);
		// Fallback: look for an existing line
		var line = (orderData.lines || []).find(function (l) { return l.hospitality_article_id === articleId; });
		return line ? Number(line.unit_price || 0) : 0;
	}

	function getOriginalLine(articleId) {
		return (orderData.lines || []).find(function (l) {
			return l.hospitality_article_id === articleId;
		}) || null;
	}

	function recalcLiveTotal() {
		var lines = orderData.lines || [];
		var total = 0;
		lines.forEach(function (line) {
			var pending = pendingLineChanges[line.hospitality_article_id];
			if (pending && pending.quantity === 0) return; // will be deleted
			if (pending) {
				total += pending.quantity * pending.unitPrice;
			} else {
				total += Number(line.amount || 0);
			}
		});
		// Also count newly added lines (no existing line in orderData)
		Object.keys(pendingLineChanges).forEach(function (artId) {
			var p = pendingLineChanges[artId];
			if (!p.lineId && p.quantity > 0) {
				total += p.quantity * p.unitPrice;
			}
		});
		var el = root.querySelector('.order-show__total-amount');
		if (el) el.textContent = total.toFixed(2);
	}

	// Quantity input — track locally, no API call
	root.addEventListener('input', function (e) {
		var input = e.target.closest('[data-qty-article]');
		if (!input) return;

		var articleId = parseInt(input.dataset.qtyArticle, 10);
		var lineId = input.dataset.qtyLine ? parseInt(input.dataset.qtyLine, 10) : null;
		var newQty = parseInt(input.value, 10) || 0;
		var unitPrice = getUnitPrice(articleId);

		// Toggle dimmed class
		var item = input.closest('.order-show__menu-item');
		if (item) {
			item.classList.toggle('order-show__menu-row--dimmed', newQty === 0);
		}

		// Check if this is actually a change vs the original data
		var origLine = getOriginalLine(articleId);
		var origQty = origLine ? origLine.quantity : 0;
		var existingPending = pendingLineChanges[articleId];
		var pendingComment = existingPending ? existingPending.comment : null;

		if (newQty === origQty && !pendingComment) {
			// Reverted to original — remove from pending
			delete pendingLineChanges[articleId];
		} else {
			pendingLineChanges[articleId] = {
				quantity: newQty,
				comment: pendingComment,
				lineId: lineId,
				unitPrice: unitPrice
			};
		}

		// Update the amount cell for this row
		var newAmount = newQty * unitPrice;
		if (item) {
			var amountEl = item.querySelector('.order-show__menu-amount');
			if (amountEl) amountEl.textContent = newAmount > 0 ? newAmount.toFixed(2) : '\u2014';
		}

		// Enable/disable comment toggle
		if (item) {
			var commentBtn = item.querySelector('[data-toggle-comment]');
			if (commentBtn) commentBtn.disabled = newQty === 0;
		}

		recalcLiveTotal();
	});

	// Comment input — track locally, no API call
	root.addEventListener('input', function (e) {
		var input = e.target.closest('[data-comment-article]');
		if (!input) return;

		var articleId = parseInt(input.dataset.commentArticle, 10);
		var lineId = input.dataset.commentLine ? parseInt(input.dataset.commentLine, 10) : null;
		var comment = input.value;

		var origLine = getOriginalLine(articleId);
		var origComment = origLine ? (origLine.comment || '') : '';
		var existingPending = pendingLineChanges[articleId];

		if (existingPending) {
			// Already tracking a quantity change — just update the comment
			existingPending.comment = comment;
		} else {
			// Comment-only change
			var origQty = origLine ? origLine.quantity : 0;
			if (comment === origComment) {
				// No change — don't add to pending
				return;
			}
			pendingLineChanges[articleId] = {
				quantity: origQty,
				comment: comment,
				lineId: lineId,
				unitPrice: getUnitPrice(articleId)
			};
		}

		// If everything is back to original, remove from pending
		if (existingPending || pendingLineChanges[articleId]) {
			var p = pendingLineChanges[articleId];
			var oQty = origLine ? origLine.quantity : 0;
			var oCom = origLine ? (origLine.comment || '') : '';
			if (p && p.quantity === oQty && (p.comment || '') === oCom) {
				delete pendingLineChanges[articleId];
			}
		}

	});

	// ═══════════════════════════════════════════════════════════════════
	// Changelog section
	// ═══════════════════════════════════════════════════════════════════

	var CHANGE_TYPE_LABELS = {
		field_update: 'edit',
		status_change: 'status',
		line_add: 'add',
		line_update: 'edit',
		line_delete: 'delete'
	};

	var CHANGE_TYPE_COLORS = {
		field_update: 'info',
		status_change: 'warning',
		line_add: 'success',
		line_update: 'info',
		line_delete: 'danger'
	};

	function renderChangelog() {
		var entries = (orderData && orderData.changelog) || [];
		var container = document.getElementById('order-changelog');
		if (!container) return;

		var bodyHtml = '';

		if (entries.length === 0) {
			bodyHtml = '<p class="app-show__empty">' + esc(lang('noChanges')) + '</p>';
		} else {
			entries.forEach(function (entry) {
				var author = entry.case_officer_name || entry.booking_user_name || '?';
				var typeLabel = CHANGE_TYPE_LABELS[entry.change_type] || entry.change_type;
				var typeColor = CHANGE_TYPE_COLORS[entry.change_type] || 'neutral';

				bodyHtml += '<div class="order-show__changelog-entry">';
				bodyHtml += '<div class="order-show__changelog-meta">';
				bodyHtml += '<span class="ds-tag ds-tag--sm" data-color="' + typeColor + '">' + esc(typeLabel) + '</span>';
				bodyHtml += '<span class="order-show__changelog-author">' + esc(author) + '</span>';
				bodyHtml += '<span class="order-show__changelog-time">' + fmtDate(entry.changed_at) + '</span>';
				bodyHtml += '</div>';

				bodyHtml += '<div class="order-show__changelog-comment">' + esc(entry.comment) + '</div>';

				// Diff display
				if (entry.old_value || entry.new_value) {
					bodyHtml += '<div class="order-show__changelog-diff">';
					var oldObj = entry.old_value || {};
					var newObj = entry.new_value || {};
					var allKeys = Object.keys(Object.assign({}, oldObj, newObj));
					allKeys.forEach(function (key) {
						var oldVal = oldObj[key] != null ? String(oldObj[key]) : '';
						var newVal = newObj[key] != null ? String(newObj[key]) : '';
						if (oldVal !== newVal) {
							bodyHtml += '<span class="order-show__changelog-field">' + esc(key) + ': ';
							if (oldVal) bodyHtml += '<del>' + esc(oldVal) + '</del> ';
							if (newVal) bodyHtml += '<ins>' + esc(newVal) + '</ins>';
							bodyHtml += '</span>';
						}
					});
					bodyHtml += '</div>';
				}

				bodyHtml += '</div>';
			});
		}

		container.innerHTML = section(lang('changelog'), bodyHtml);
	}

})();
