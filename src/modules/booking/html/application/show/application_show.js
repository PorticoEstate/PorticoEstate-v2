(function () {
	'use strict';

	var root = document.getElementById('application-show');
	if (!root) return;

	// Strip query string from base URL — phpgw_link() adds click_history etc.
	var apiUrl = root.dataset.apiUrl.split('?')[0];

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

	function escNl(str) {
		return esc(str).replace(/\n/g, '<br>');
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

	// Shared inline SVG icon set (Designsystemet-style line icons).
	var ICONS = {
		chevron: '<svg class="app-show__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
		reply: '<svg class="app-show__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 17 4 12l5-5"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>',
		send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 9.5 21 3m0 0-6.5 18-4-8-8-4L21 3Z"/></svg>',
		message: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-9 8.3 9.6 9.6 0 0 1-3.5-.7L3 21l1.9-4.5A8.38 8.38 0 0 1 12 3a8.5 8.5 0 0 1 9 8.5Z"/></svg>',
		note: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="M9 13h6M9 17h4"/></svg>',
		userCheck: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg>',
		eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
		checkCircle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.8 10A9.5 9.5 0 1 1 14 3.5"/><path d="m9 11 3 3 9-9"/></svg>',
		xCircle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="m15 9-6 6M9 9l6 6"/></svg>',
		swap: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m17 2 4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
		pdf: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M19 9v9a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7Z"/><path d="M9 17v-4h1.5a1.5 1.5 0 0 1 0 3H9"/></svg>',
		edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
		back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>',
		contact: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2.2"/><path d="M5.5 16a3.5 3.5 0 0 1 7 0M15 9h4M15 13h4"/></svg>',
		building: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21h16M6 21V5a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v16M15 9h3a1 1 0 0 1 1 1v11"/><path d="M9 8h2M9 12h2"/></svg>',
		invoice: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2h9l4 4v14a1 1 0 0 1-1.4.9L15 20l-2.5 1.6L10 20l-2.5 1.6L5 20l-1.6.9A1 1 0 0 1 2 20V4"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
		text: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>',
		target: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/></svg>',
		people: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13A4 4 0 0 1 16 11"/></svg>',
		cart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M2 3h2.2l2.3 12.4a1 1 0 0 0 1 .8h8.7a1 1 0 0 0 1-.78L21 7H6"/></svg>',
		calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
		repeat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m17 2 4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
		doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>',
		link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>'
	};

	// Designsystemet Spinner (SVG markup; sized via data-size).
	function spinner(size) {
		return '<svg class="ds-spinner" data-size="' + (size || 'md') + '" viewBox="0 0 50 50" role="img" aria-label="' + esc(lang('loading')) + '">' +
			'<circle class="ds-spinner__background" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle>' +
			'<circle class="ds-spinner__circle" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle>' +
			'</svg>';
	}

	function statusTag(status) {
		var colorMap = {
			'new': 'info', 'pending': 'warning',
			'accepted': 'success', 'rejected': 'danger'
		};
		var color = colorMap[(status || '').toLowerCase()] || 'neutral';
		return '<span class="ds-tag" data-color="' + color + '">' + esc(status) + '</span>';
	}

	// A titled card. opts.icon = ICONS key/svg, opts.aside = right-aligned header html.
	function section(title, bodyHtml, opts) {
		opts = opts || {};
		if (opts.hideEmpty && !bodyHtml) return '';
		var iconHtml = opts.icon ? '<span class="app-show__card-icon">' + opts.icon + '</span>' : '';
		var aside = opts.aside ? '<span class="app-show__card-aside">' + opts.aside + '</span>' : '';
		return '<section class="ds-card app-show__card">' +
			'<div class="app-show__card-head">' +
			'<div class="app-show__card-title">' + iconHtml + '<h2 class="ds-heading" data-size="xs">' + esc(title) + '</h2></div>' +
			aside +
			'</div>' +
			(bodyHtml || '<p class="app-show__empty">&mdash;</p>') +
			'</section>';
	}

	// Wrap label/value items (from field()) in a definition grid.
	function defGrid(itemsHtml, cols) {
		if (!itemsHtml) return '';
		return '<dl class="app-show__def' + (cols === 3 ? ' app-show__def--3' : '') + '">' + itemsHtml + '</dl>';
	}

	function field(label, value) {
		if (value == null || value === '') return '';
		return '<div><dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd></div>';
	}

	function fieldHtml(label, valueHtml, wide) {
		if (!valueHtml) return '';
		return '<div' + (wide ? ' class="app-show__def-wide"' : '') + '><dt>' + esc(label) + '</dt><dd>' + valueHtml + '</dd></div>';
	}

	function showToast(message, type) {
		var toast = document.createElement('div');
		toast.className = 'ds-alert app-show__toast';
		toast.setAttribute('data-color', type || 'success');
		toast.setAttribute('role', 'status');
		toast.innerHTML = '<p class="ds-paragraph">' + esc(message) + '</p>';
		document.body.appendChild(toast);
		setTimeout(function () { toast.remove(); }, 3000);
	}

	function fetchJson(url) {
		return fetch(url, { credentials: 'same-origin' }).then(function (res) {
			if (!res.ok) throw new Error('HTTP ' + res.status);
			return res.json();
		});
	}

	function postJson(url) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: '{}'
		}).then(function (res) {
			if (!res.ok) throw new Error('HTTP ' + res.status);
			return res.json();
		});
	}

	function postJsonBody(url, data) {
		return fetch(url, {
			method: 'POST',
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

	function postJsonToLegacy(url, data) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(data)
		}).then(function (res) {
			return res.json().then(function (json) {
				// Forward the FULL failure body (errors + conflict_details /
				// conflict_links / conflict_count) so callers can show overlaps.
				if (!res.ok) throw Object.assign({ status: res.status, errors: {} }, json);
				return json;
			});
		});
	}

	function buildDateParams(app, date, agegroups, audience) {
		// In a combined cart each date belongs to its own sub-application. Link the
		// created event/allocation/booking to THAT sub-application, not the app whose
		// page we happen to be viewing — otherwise associations are attributed to the
		// wrong application and the per-sub-app accept/reject logic breaks.
		var params = {
			from_: date.from_ || '',
			to_: date.to_ || '',
			cost: '0',
			application_id: date.application_id || app.id,
			reminder: '0'
		};

		var copyFields = [
			'activity_id', 'name', 'organizer', 'homepage', 'description',
			'equipment', 'contact_name', 'contact_email', 'contact_phone',
			'building_id', 'building_name', 'customer_identifier_type',
			'customer_ssn', 'customer_organization_number',
			'customer_organization_id', 'customer_organization_name'
		];
		copyFields.forEach(function (f) {
			params[f] = app[f] != null ? String(app[f]) : '';
		});
		// Prefer the date's own sub-application name when present (combined carts).
		if (date.application_name) {
			params.name = String(date.application_name);
		}

		var male = {}, female = {};
		(agegroups || []).forEach(function (ag) {
			male[ag.id] = ag.male || 0;
			female[ag.id] = ag.female || 0;
		});
		params.male = male;
		params.female = female;

		params.audience = (audience && audience.selected) || [];
		params.resources = (date.resources || []);

		return params;
	}

	// ═══════════════════════════════════════════════════════════════════
	// Data fetching & initialization
	// ═══════════════════════════════════════════════════════════════════

	Promise.all([
		fetchJson(apiUrl),                     // 0: core application
		fetchJson(apiUrl + '/dates'),          // 1: dates with collision
		fetchJson(apiUrl + '/resources'),      // 2: resources
		fetchJson(apiUrl + '/agegroups'),       // 3: agegroups
		fetchJson(apiUrl + '/audience'),        // 4: audience
		fetchJson(apiUrl + '/comments'),        // 5: comments
		fetchJson(apiUrl + '/internal-notes'),  // 6: internal notes
		fetchJson(apiUrl + '/documents'),       // 7: documents
		fetchJson(apiUrl + '/orders'),          // 8: orders
		fetchJson(apiUrl + '/related'),         // 9: related applications
		fetchJson(apiUrl + '/associations'),    // 10: associations
	]).then(function (results) {
		render({
			application:    results[0],
			dates:          results[1],
			resources:      results[2],
			agegroups:      results[3],
			audience:       results[4],
			comments:       results[5],
			internal_notes: results[6],
			documents:      results[7],
			orders:         results[8],
			related:        results[9],
			associations:   results[10],
		});
	}).catch(function (err) {
		document.getElementById('application-loading').hidden = true;
		var errEl = document.getElementById('application-error');
		errEl.hidden = false;
		document.getElementById('application-error-message').textContent =
			lang('error') + ': ' + err.message;
	});

	// Tab switching
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

	function render(data) {
		var app = data.application;

		document.getElementById('application-loading').hidden = true;
		document.getElementById('application-content').hidden = false;

		renderToolbar(app);
		renderHeader(app);
		renderDetails(app, data);
		renderTerms(app);
		renderNotes(data.internal_notes || []);
		renderComments(data.comments || []);
		renderHospitalityOrders(data);

		// Activate tab from URL hash
		var hash = window.location.hash.replace('#', '');
		if (hash && document.getElementById('tab-' + hash)) {
			var tab = root.querySelector('[data-tab="' + hash + '"]');
			if (tab) tab.click();
		}
	}

	// Activate tab on external hash change
	window.addEventListener('hashchange', function () {
		var hash = window.location.hash.replace('#', '');
		var tab = root.querySelector('[data-tab="' + hash + '"]');
		if (tab && !tab.classList.contains('app-show__tab--active')) tab.click();
	});

	// Building schedule popup from header meta
	root.addEventListener('click', function (e) {
		var link = e.target.closest('[data-building-schedule]');
		if (!link) return;
		e.preventDefault();
		var buildingId = link.dataset.buildingSchedule;
		var scheduleUrl = '/?menuaction=bookingfrontend.uibuilding.schedule&id=' + buildingId + '&backend=1';
		window.open(scheduleUrl, '', 'width=1048,height=600,scrollbars=yes');
	});

	// ═══════════════════════════════════════════════════════════════════
	// Toolbar
	// ═══════════════════════════════════════════════════════════════════

	function renderToolbar(app) {
		var tb = app.toolbar || {};
		var isCO = tb.case_officer_is_current_user;
		var hasCO = tb.has_case_officer;

		var icons = ICONS;

		function menuItem(label, opts) {
			opts = opts || {};
			var disabled = opts.disabled;
			var attrs = ' class="ds-dropdown__item"';
			if (opts.color) attrs += ' data-color="' + esc(opts.color) + '"';
			if (disabled) attrs += ' aria-disabled="true" tabindex="-1"';
			if (opts.title) attrs += ' title="' + esc(opts.title) + '"';
			if (opts.action) attrs += ' data-action="' + esc(opts.action) + '"';
			var inner = (opts.icon || '') + '<span>' + esc(label) +
				(opts.desc ? '<span class="ds-dropdown__item-desc">' + esc(opts.desc) + '</span>' : '') +
				'</span>';
			if (opts.href && !disabled) {
				return '<li><a' + attrs + ' href="' + esc(opts.href) + '">' + inner + '</a></li>';
			}
			return '<li><button type="button"' + attrs + '>' + inner + '</button></li>';
		}

		// Build a Designsystemet Dropdown (native Popover API).
		// triggerAttrs is the ds-button attribute string (variant/color);
		// groups is an array of { heading?, items: [<li>…] } separated by rules.
		function dropdown(id, triggerAttrs, triggerHtml, groups) {
			var html = '<button type="button" class="ds-button app-show__menu-trigger" ' + triggerAttrs + ' popovertarget="' + id + '">' + triggerHtml + '</button>';
			html += '<div class="ds-dropdown app-show__menu" popover id="' + id + '">';
			groups.forEach(function (g, i) {
				if (i > 0) html += '<hr class="app-show__menu-sep">';
				if (g.heading) html += '<h3>' + esc(g.heading) + '</h3>';
				html += '<ul>' + g.items.filter(Boolean).join('') + '</ul>';
			});
			html += '</div>';
			return html;
		}

		var html = '<div class="app-show__toolbar">';

		// ── Reply / messages dropdown (secondary) ──
		var svarItems = [
			menuItem(lang('sendReplyToApplicant'), { disabled: !isCO, action: 'comment-modal', icon: icons.send }),
			menuItem(lang('sendMessageToCaseOfficer'), {
				disabled: !tb.messenger_enabled || isCO || !hasCO,
				action: 'messenger-modal', icon: icons.message
			}),
			menuItem(lang('createInternalNote'), { disabled: !isCO, action: 'internal-note-modal', icon: icons.note })
		];
		html += dropdown('menu-svar', 'data-variant="secondary" data-color="neutral"',
			icons.reply + esc(lang('reply')) + icons.chevron,
			[{ items: svarItems }]);

		// ── Actions dropdown (primary) ──
		var caseGroup = [];
		if (isCO) {
			caseGroup.push(menuItem(lang('unassignMe'), { action: 'unassign', icon: icons.userCheck }));
			var dashLabel = tb.display_in_dashboard === 1
				? lang('hideFromDashboard')
				: lang('displayInDashboard');
			caseGroup.push(menuItem(dashLabel, { action: 'toggle-dashboard', icon: icons.eye }));
		} else {
			var assignLabel = hasCO ? lang('reAssignToMe') : lang('assignToMe');
			caseGroup.push(menuItem(assignLabel, { action: 'assign', icon: icons.userCheck }));
		}
		caseGroup.push(menuItem(lang('changeCaseOfficer'), { action: 'change-user-modal', icon: icons.swap }));

		var decisionGroup = [];
		if (tb.show_accept) {
			if (tb.num_associations === 0) {
				decisionGroup.push(menuItem(lang('acceptRequiresAssociations'), { disabled: true, color: 'success', icon: icons.checkCircle }));
			} else {
				decisionGroup.push(menuItem(lang('acceptApplication'), {
					disabled: !isCO, color: 'success', action: 'accept-modal', icon: icons.checkCircle,
					title: !isCO ? lang('notCaseOfficerWarning') : ''
				}));
			}
		}
		if (tb.show_reject) {
			decisionGroup.push(menuItem(lang('rejectApplication'), {
				disabled: !isCO, color: 'danger', action: 'reject-modal', icon: icons.xCircle,
				title: !isCO ? lang('notCaseOfficerWarning') : ''
			}));
		}

		var docGroup = [];
		if (tb.external_archive && !tb.external_archive_key) {
			docGroup.push(menuItem(lang('pdfExportToArchive'), { disabled: !isCO, action: 'export-pdf', icon: icons.pdf }));
			docGroup.push(menuItem(lang('preview'), { disabled: !isCO, action: 'export-pdf-preview', icon: icons.eye }));
		}
		docGroup.push(menuItem(lang('edit'), { disabled: !isCO, href: tb.edit_url, icon: icons.edit }));
		if (tb.show_edit_selection) {
			docGroup.push(menuItem(lang('editInvoicing'), { disabled: !isCO, href: tb.edit_invoicing_url, icon: icons.edit }));
		}

		var navGroup = [
			menuItem(lang('backToDashboard'), { href: tb.dashboard_url, icon: icons.back }),
			menuItem(lang('backToOverview'), { href: tb.applications_url, icon: icons.back })
		];

		var groups = [{ items: caseGroup }];
		if (decisionGroup.length) groups.push({ items: decisionGroup });
		groups.push({ items: docGroup });
		groups.push({ items: navGroup });

		html += dropdown('menu-handlinger', 'data-variant="primary" data-color="accent"',
			esc(lang('actions')) + icons.chevron, groups);

		html += '</div>';

		renderToolbar._toolbarHtml = html;

		var warningHtml = '';
		if (!isCO) {
			var warnText = !hasCO ? lang('noCaseOfficerWarning') : lang('differentCaseOfficerWarning');
			warningHtml = '<div class="ds-alert app-show__alert" data-color="warning" role="alert">' +
				'<p class="ds-paragraph">' + esc(warnText) + '</p></div>';
		}
		document.getElementById('application-toolbar').innerHTML = warningHtml;

		// Wire up the dropdown/action listeners exactly once. renderToolbar may be
		// called again to refresh the toolbar (e.g. after recurring allocations are
		// created); these handlers are delegated on root/document so they keep working
		// against the re-rendered markup without being re-attached (which would make
		// each action fire multiple times).
		if (renderToolbar._wired) return;
		renderToolbar._wired = true;

		// Close the dropdown once an enabled item is activated.
		root.addEventListener('click', function (e) {
			var item = e.target.closest('.ds-dropdown__item');
			if (!item || item.getAttribute('aria-disabled') === 'true') return;
			var pop = item.closest('.ds-dropdown');
			if (pop && pop.matches(':popover-open')) pop.hidePopover();
		});

		// Anchor each popover under its trigger (anchorless popover fallback).
		function placePopover(pop) {
			var trigger = document.querySelector('[popovertarget="' + pop.id + '"]');
			if (!trigger) return;
			var t = trigger.getBoundingClientRect();
			var p = pop.getBoundingClientRect();
			var gap = 6;
			var left = Math.max(8, Math.min(t.right - p.width, window.innerWidth - p.width - 8));
			var top = t.bottom + gap;
			if (top + p.height > window.innerHeight - 8) top = Math.max(8, t.top - p.height - gap);
			pop.style.position = 'fixed';
			pop.style.margin = '0';
			pop.style.left = left + 'px';
			pop.style.top = top + 'px';
		}
		document.addEventListener('toggle', function (e) {
			var pop = e.target;
			if (e.newState === 'open' && pop.classList && pop.classList.contains('app-show__menu')) {
				placePopover(pop);
			}
		}, true);
		window.addEventListener('resize', function () {
			root.querySelectorAll('.app-show__menu:popover-open').forEach(placePopover);
		});

		// Action handling
		root.addEventListener('click', function (e) {
			var item = e.target.closest('[data-action]');
			if (!item || item.getAttribute('aria-disabled') === 'true') return;

			var action = item.dataset.action;

			if (action === 'assign') {
				item.textContent = '...';
				postJson(apiUrl + '/assign').then(function () {
					window.location.reload();
				}).catch(function (err) {
					alert(lang('error') + ': ' + err.message);
					item.textContent = lang('assignToMe');
				});
			} else if (action === 'unassign') {
				item.textContent = '...';
				postJson(apiUrl + '/unassign').then(function () {
					window.location.reload();
				}).catch(function (err) {
					alert(lang('error') + ': ' + err.message);
					item.textContent = lang('unassignMe');
				});
			} else if (action === 'toggle-dashboard') {
				item.textContent = '...';
				postJson(apiUrl + '/toggle-dashboard').then(function () {
					window.location.reload();
				}).catch(function (err) {
					alert(lang('error') + ': ' + err.message);
				});
			} else if (action === 'export-pdf') {
				if (confirm(lang('transferCaseConfirm'))) {
					window.location.href = tb.export_pdf_url;
				}
			} else if (action === 'export-pdf-preview') {
				window.open(tb.export_pdf_url + '&preview=1', '_blank');
			}
			// Modal actions
		if (action === 'comment-modal') showCommentModal();
		else if (action === 'internal-note-modal') showInternalNoteModal();
		else if (action === 'messenger-modal') showMessengerModal();
		else if (action === 'accept-modal') showAcceptModal();
		else if (action === 'reject-modal') showRejectModal();
		else if (action === 'change-user-modal') showChangeUserModal();
		});
	}

	// ═══════════════════════════════════════════════════════════════════
	// Header
	// ═══════════════════════════════════════════════════════════════════

	function renderHeader(app) {
		var toolbarHtml = renderToolbar._toolbarHtml || '';
		var titleText = app.related_application_count > 1
			? lang('combinedApplication') + ' (' + app.related_application_count + ') — ' + esc(app.building_name)
			: lang('application') + ' #' + esc(app.id);
		var html = '<div class="app-show__title-row">' +
			'<div class="app-show__title-left">' +
			'<h1 class="ds-heading app-show__title" data-size="lg">' + titleText + '</h1>' +
			statusTag(app.status) +
			'</div>' +
			toolbarHtml +
			'</div>';

		// Key facts as a definition grid inside a card.
		var buildingVal = esc(app.building_name);
		if (app.building_id) {
			buildingVal += ' <a href="javascript:void(0)" class="app-show__schedule-link" data-building-schedule="' + esc(app.building_id) + '" title="' + lang('schedule') + '" aria-label="' + lang('schedule') + '">' + ICONS.calendar + '</a>';
		}
		var meta = '<dl><dt>' + lang('building') + '</dt><dd>' + buildingVal + '</dd></dl>';
		meta += '<dl><dt>' + lang('created') + '</dt><dd>' + fmtDate(app.created) + '</dd></dl>';
		if (app.modified) {
			meta += '<dl><dt>' + lang('modified') + '</dt><dd>' + fmtDate(app.modified) + '</dd></dl>';
		}
		var officerVal;
		if (app.case_officer_name) {
			officerVal = esc(app.case_officer_name);
			if (app.case_officer_is_current_user) {
				officerVal += ' <span class="ds-tag" data-color="success" data-size="sm">&#10003;</span>';
			}
		} else {
			officerVal = '<em>' + lang('notAssigned') + '</em>';
		}
		meta += '<dl><dt>' + lang('caseOfficer') + '</dt><dd>' + officerVal + '</dd></dl>';
		if (app.related_application_count > 1) {
			meta += '<dl><dt>' + lang('relatedApps') + '</dt><dd>' + app.related_application_count + '</dd></dl>';
		}
		if (app.num_associations > 0) {
			meta += '<dl><dt>' + lang('associations') + '</dt><dd>' + app.num_associations + '</dd></dl>';
		}
		if (app.toolbar && app.toolbar.external_archive_key) {
			meta += '<dl><dt>' + lang('archiveKey') + '</dt><dd>' + esc(app.toolbar.external_archive_key) + '</dd></dl>';
		}
		html += '<section class="ds-card app-show__meta">' + meta + '</section>';

		document.getElementById('application-header').innerHTML = html;
	}

	// ═══════════════════════════════════════════════════════════════════
	// Details tab
	// ═══════════════════════════════════════════════════════════════════

	function renderDetails(app, data) {
		var html = '';
		var isCombined = app.related_application_count > 1;
		var isCO = app.toolbar && app.toolbar.case_officer_is_current_user;

		// Related application cards (at top for combined apps)
		var related = data.related || [];
		if (related.length > 1) {
			var relHtml = '<div class="app-show__card-grid">';
			related.forEach(function (rel) {
				var isCurrent = rel.id === app.id;
				relHtml += '<div class="ds-card app-show__app-card' + (isCurrent ? ' app-show__app-card--current' : '') + '">';
				relHtml += '<div class="app-show__app-card-header">';
				relHtml += '<span>' + lang('application') + ' #' + rel.id + '</span>';
				relHtml += ' ' + statusTag(rel.status);
				relHtml += '</div>';
				if (rel.name) {
					relHtml += '<div class="app-show__app-card-meta">' + esc(rel.name) + '</div>';
				}
				if (rel.date_ranges && rel.date_ranges.length) {
					relHtml += '<div class="app-show__app-card-dates">';
					rel.date_ranges.forEach(function (dr) {
						relHtml += '<div>' + fmtDate(dr.from_) + ' – ' + fmtDate(dr.to_) + '</div>';
					});
					relHtml += '</div>';
				}
				if (rel.resource_names && rel.resource_names.length) {
					relHtml += '<div class="app-show__app-card-field">' + lang('resources') + ': ' + rel.resource_names.map(esc).join(', ') + '</div>';
				}
				if (rel.description) {
					relHtml += '<div class="app-show__app-card-field">' + esc(rel.description) + '</div>';
				}
				var editUrl = '/?menuaction=booking.uiapplication.edit&id=' + rel.id + '&selected_app_id=' + rel.id + '&hide_invoicing=1';
				relHtml += '<div class="app-show__app-card-actions">';
				if (isCO) {
					relHtml += '<a class="ds-button" data-variant="secondary" data-color="neutral" data-size="sm" href="' + esc(editUrl) + '">' + lang('edit') + '</a>';
				} else {
					relHtml += '<span class="ds-button app-show__toolbar-disabled" data-variant="secondary" data-color="neutral" data-size="sm" aria-disabled="true">' + lang('edit') + '</span>';
				}
				relHtml += '</div>';
				relHtml += '</div>';
			});
			relHtml += '</div>';
			html += relHtml;
		}

		// Contact information
		var contactHtml = '';
		contactHtml += field(lang('contactName'), app.contact_name);
		contactHtml += field(lang('contactEmail'), app.contact_email);
		contactHtml += field(lang('contactPhone'), app.contact_phone);
		html += section(lang('contact'), defGrid(contactHtml, 3), { icon: ICONS.contact });

		// Organization
		if (app.organization) {
			var orgHtml = '';
			orgHtml += field(lang('name'), app.organization.name);
			orgHtml += field(lang('orgNumber'), app.organization.organization_number);
			if (app.organization.in_tax_register != null) {
				orgHtml += field(lang('inTaxRegister'),
					(app.organization.in_tax_register === 1 || app.organization.in_tax_register === '1') ? lang('yes') : lang('no'));
			}
			html += section(lang('organization'), defGrid(orgHtml, 3), { icon: ICONS.building });
		} else if (app.customer_identifier_type === 'ssn' && app.customer_ssn) {
			var ssnHtml = field(lang('ssn'), app.customer_ssn.substring(0, 6) + '*****');
			html += section(lang('invoice'), defGrid(ssnHtml, 3), { icon: ICONS.invoice });
		}

		// Invoice / Address
		var invoiceHtml = '';
		if (app.customer_identifier_type === 'organization_number') {
			invoiceHtml += field(lang('orgNumber'), app.customer_organization_number);
		}
		invoiceHtml += field(lang('street'), app.responsible_street);
		invoiceHtml += field(lang('zip'), app.responsible_zip_code);
		invoiceHtml += field(lang('city'), app.responsible_city);
		if (invoiceHtml) {
			html += section(lang('invoice'), defGrid(invoiceHtml, 3), { icon: ICONS.invoice });
		}

		// Event details, audience, agegroups (hidden for simple bookings)
		if (!app.simple) {
			var eventHtml = '';
			eventHtml += field(lang('activity'), app.activity_name);
			eventHtml += field(lang('eventName'), app.name);
			eventHtml += field(lang('organizer'), app.organizer);
			if (app.homepage) {
				eventHtml += fieldHtml(lang('homepage'), '<a href="' + esc(app.homepage) + '" target="_blank">' + esc(app.homepage) + '</a>');
			}
			eventHtml += fieldHtml(lang('description'), app.description ? escNl(app.description) : '', true);
			eventHtml += fieldHtml(lang('equipment'), app.equipment ? escNl(app.equipment) : '', true);
			html += section(lang('description'), defGrid(eventHtml, 2), { icon: ICONS.text });

			var audienceData = data.audience || {};
			var selected = audienceData.selected || [];
			var available = audienceData.available || [];
			if (selected.length > 0 && available.length > 0) {
				var audienceNames = [];
				available.forEach(function (a) {
					if (selected.indexOf(a.id) !== -1) audienceNames.push(a.name);
				});
				if (audienceNames.length > 0) {
					html += section(lang('targetAudience'),
						'<div class="app-show__tags-row">' + audienceNames.map(function (n) {
							return '<span class="ds-tag">' + esc(n) + '</span>';
						}).join('') + '</div>',
						{ icon: ICONS.target });
				}
			}

			var rawAgegroups = data.agegroups || [];

			function renderAgTable(groups) {
				var tbl = '<table class="ds-table" data-size="sm" data-zebra>' +
					'<thead><tr><th>' + lang('name') + '</th><th class="app-show__num">' + lang('male') + '</th><th class="app-show__num">' + lang('female') + '</th></tr></thead><tbody>';
				var hasData = false;
				(groups || []).forEach(function (ag) {
					var m = parseInt(ag.male || 0);
					var f = parseInt(ag.female || 0);
					if (m > 0 || f > 0) {
						hasData = true;
						tbl += '<tr><td>' + esc(ag.name) + '</td><td class="app-show__num">' + m + '</td><td class="app-show__num">' + f + '</td></tr>';
					}
				});
				tbl += '</tbody></table>';
				return hasData ? tbl : '';
			}

			if (rawAgegroups.combined) {
				// Combined application — per-app or single depending on all_same
				if (rawAgegroups.all_same) {
					var agContent = renderAgTable((rawAgegroups.per_app[0] || {}).agegroups || []);
					if (agContent) html += section(lang('participants'), agContent, { icon: ICONS.people });
				} else {
					var agContent = '';
					(rawAgegroups.per_app || []).forEach(function (entry) {
						var tbl = renderAgTable(entry.agegroups || []);
						if (tbl) {
							var heading = esc(entry.application_name) + ' (#' + entry.application_id + ')';
							if (entry.dates && entry.dates.length === 1) {
								var f = entry.dates[0].from_ || '';
								var t = entry.dates[0].to_ || '';
								var fDay = f.substring(0, 10);
								var tDay = t.substring(0, 10);
								if (fDay === tDay) {
									heading += ' ' + fmtDate(f) + ' – ' + fmtDate(t).replace(/^.*,\s*/, '');
								} else {
									heading += ' ' + fmtDate(f) + ' – ' + fmtDate(t);
								}
							}
							agContent += '<h4>' + heading + '</h4>' + tbl;
						}
					});
					if (agContent) html += section(lang('participants'), agContent, { icon: ICONS.people });
				}
			} else if (rawAgegroups.length > 0) {
				// Single application — flat array
				var agContent = renderAgTable(rawAgegroups);
				if (agContent) html += section(lang('participants'), agContent, { icon: ICONS.people });
			}
		}

		// Dates — skip for recurring apps (the recurring preview table replaces this)
		var isRecurring = !!app.recurring_data;
		var dates = data.dates || [];

		// Build association lookup: normalised from_ → true
		// Dates API returns ISO 8601 ("2026-02-19T19:30:00+01:00"),
		// associations come as raw PG timestamps ("2026-02-19 19:30:00").
		// Strip timezone and separators to get a canonical "YYYY-MM-DDTHH:MM:SS" key.
		function normDate(s) {
			if (!s) return '';
			// Take only the first 19 chars ("2026-02-19T19:30:00" or "2026-02-19 19:30:00")
			// and normalise the separator to 'T'.
			return s.substring(0, 19).replace(' ', 'T');
		}
		var assocFromSet = {};
		(data.associations || []).forEach(function (a) {
			var key = normDate(a.from_);
			if (key) assocFromSet[key] = true;
		});

		// Build params map for each date (for the create dropdown)
		var dateParamsMap = {};

		// Allocations are org-level grants: an individual/SSN application has no
		// organisation, so the Allocation option is hidden for those (booking/event
		// only). Booking/event remain available to individuals.
		var hasOrg = !!(app.customer_organization_id || app.customer_organization_number);

		if (dates.length > 0 && !isRecurring) {
			var datesHtml = '<table class="ds-table" data-size="sm" data-zebra id="dates-table"><thead><tr>';
			if (isCombined) datesHtml += '<th>' + lang('application') + '</th>';
			datesHtml += '<th>' + lang('from') + '</th><th>' + lang('to') + '</th><th>' + lang('resources') + '</th><th>' + lang('status') + '</th><th>' + lang('handling') + '</th></tr></thead><tbody>';
			dates.forEach(function (d) {
				var hasAssoc = !!assocFromSet[normDate(d.from_)];
				var collisionTag;
				if (hasAssoc) {
					collisionTag = '<span class="ds-tag" data-color="neutral">' + lang('dateCreated') + '</span>';
				} else if (d.collision) {
					collisionTag = '<span class="ds-tag" data-color="danger">' + lang('collision') + '</span>';
				} else {
					collisionTag = '<span class="ds-tag" data-color="success">' + lang('noCollision') + '</span>';
				}

				// Schedule link: show on collision + case officer + no association
				var scheduleIcon = '';
				if (isCO && d.collision && !hasAssoc) {
					scheduleIcon = ' <a href="javascript:void(0)" class="app-show__schedule-link" data-schedule-date="' + esc(d.from_) + '" title="' + lang('schedule') + '">' +
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' +
						'</a>';
				}

				// Build params for this date — extract flat agegroups for combined apps
				var flatAgegroups = rawAgegroups.combined
					? ((rawAgegroups.per_app || [])[0] || {}).agegroups || []
					: rawAgegroups || [];
				dateParamsMap[d.id] = buildDateParams(app, d, flatAgegroups, data.audience || {});

				// Action: split button — primary "Lag arrangement" + caret dropdown for the rest
				var actionHtml;
				if (hasAssoc) {
					actionHtml = '<span class="ds-tag" data-color="neutral">' + lang('dateCreated') + '</span>';
				} else if (!isCO) {
					actionHtml = '<div class="app-show__split">' +
						'<button type="button" class="ds-button" data-variant="primary" data-color="accent" data-size="sm" disabled>' + esc(lang('createEvent')) + '</button>' +
						'<button type="button" class="ds-button app-show__split-toggle" data-variant="secondary" data-color="accent" data-size="sm" disabled aria-label="' + esc(lang('dateActions')) + '">' + ICONS.chevron + '</button>' +
						'</div>';
				} else {
					var menuId = 'datemenu-' + d.id;
					actionHtml = '<div class="app-show__split">' +
						'<button type="button" class="ds-button" data-variant="primary" data-color="accent" data-size="sm" data-create="event" data-date-id="' + d.id + '">' + esc(lang('createEvent')) + '</button>' +
						'<button type="button" class="ds-button app-show__split-toggle" data-variant="secondary" data-color="accent" data-size="sm" popovertarget="' + menuId + '" aria-label="' + esc(lang('dateActions')) + '">' + ICONS.chevron + '</button>' +
						'<div class="ds-dropdown app-show__menu app-show__split-menu" popover id="' + menuId + '"><ul>' +
						(hasOrg ? '<li><button type="button" class="ds-dropdown__item" data-create="allocation" data-date-id="' + d.id + '"><span>' + esc(lang('createAllocation')) + '</span></button></li>' : '') +
						'<li><button type="button" class="ds-dropdown__item" data-create="booking" data-date-id="' + d.id + '"><span>' + esc(lang('createBooking')) + '</span></button></li>' +
						// Reject only this sub-application (combined carts) — siblings stay open.
						(isCombined ? '<li><button type="button" class="ds-dropdown__item" data-color="danger" data-reject-app="' + esc(d.application_id) + '"><span>' + esc(lang('rejectApplication')) + '</span></button></li>' : '') +
						'</ul></div></div>';
				}

				datesHtml += '<tr>';
				if (isCombined) datesHtml += '<td>#' + esc(d.application_id) + '</td>';
				datesHtml += '<td>' + fmtDate(d.from_) + '</td><td>' + fmtDate(d.to_) + '</td><td>' + esc(d.resource_names) + '</td><td>' + collisionTag + scheduleIcon + '</td><td>' + actionHtml + '</td></tr>';
			});
			datesHtml += '</tbody></table>';
			html += section(lang('dates'), datesHtml, { icon: ICONS.calendar });
		}

		// Decode HTML entities in a string. conflict_links[].link comes from the
		// legacy self::link() and is entity-encoded (&amp;). Setting it via the
		// .href *property* does NOT decode, so a bare &amp; would break the query
		// — decode first. (textarea.value is the standard, XSS-safe decode.)
		function decodeEntities(str) {
			if (str == null) return '';
			var ta = document.createElement('textarea');
			ta.innerHTML = String(str);
			return ta.value;
		}

		// Remove any inline overlap message from a date's action cell.
		function clearConflict(cell) {
			if (!cell) return;
			var prev = cell.querySelector('.app-show__conflict');
			if (prev) prev.remove();
		}

		// Render the inline overlap message for a failed create. On a real
		// overlap the endpoint returns conflict_links ({ item_N: {name,link,type} });
		// we show "Overlaps with: <edit links>" so the officer can resolve it
		// without navigating away. Falls back to plain error text otherwise.
		function renderConflict(cell, err) {
			if (!cell) return;
			clearConflict(cell);
			var box = document.createElement('div');
			box.className = 'app-show__conflict';
			box.setAttribute('role', 'alert');

			var links = err && err.conflict_links && typeof err.conflict_links === 'object'
				? Object.keys(err.conflict_links).map(function (k) { return err.conflict_links[k]; })
				: [];
			if (links.length) {
				box.appendChild(document.createTextNode(lang('overlapsWith') + ': '));
				links.forEach(function (c, i) {
					if (i) box.appendChild(document.createTextNode(', '));
					var a = document.createElement('a');
					a.href = decodeEntities(c.link) || '#';
					a.target = '_blank';
					a.rel = 'noopener noreferrer';
					a.textContent = decodeEntities(c.name) || ('#' + (i + 1));
					box.appendChild(a);
				});
			} else {
				var msg = lang('error');
				var flat = err && err.errors ? [].concat.apply([], Object.values(err.errors)).filter(Boolean) : [];
				if (flat.length) msg += ': ' + flat.join(', ');
				box.textContent = msg;
			}
			cell.appendChild(box);
		}

		// True when a create failed specifically because a group must be chosen.
		// Single-group orgs auto-derive server-side (Tier 1), so this only fires
		// for multi-group-org bookings that need an explicit choice.
		function needsGroup(err) {
			return !!(err && err.errors && Object.keys(err.errors).some(function (k) {
				return /group/i.test(k);
			}));
		}

		// Remove any inline group picker from a date's action cell.
		function clearGroupPicker(cell) {
			if (!cell) return;
			var prev = cell.querySelector('.app-show__group-picker');
			if (prev) prev.remove();
		}

		// Slot fulfilled: replace the whole split (all create options for this
		// date/slot) with a success check + in-place Edit link, and clear any
		// conflict / picker left in the cell. Different slots stay untouched.
		function collapseToEditLink(btn, cell, result) {
			var link = document.createElement('a');
			link.href = result.edit_url;
			link.className = 'ds-button';
			link.setAttribute('data-variant', 'tertiary');
			link.setAttribute('data-color', 'success');
			link.setAttribute('data-size', 'sm');
			link.setAttribute('data-created', btn.dataset.create);
			link.setAttribute('aria-label', lang('edit'));
			link.innerHTML = ICONS.checkCircle + '<span>' + esc(lang('edit')) + '</span>';
			var split = btn.closest('.app-show__split');
			(split || btn).replaceWith(link);
			clearConflict(cell);
			clearGroupPicker(cell);
		}

		// Inline group picker for multi-group-org bookings. The booking create
		// resolves the org from the application (customer_organization_number),
		// which can differ from customer_organization_id — so list groups for the
		// org the create actually used (err.organization_id), falling back to
		// customer_organization_id only if the backend didn't echo it.
		function renderGroupPicker(cell, btn, params, url, err) {
			if (!cell) return;
			clearConflict(cell);
			clearGroupPicker(cell);
			var orgId = (err && err.organization_id) || params.customer_organization_id;
			if (!orgId) { renderConflict(cell, err); return; }

			var box = document.createElement('div');
			box.className = 'app-show__group-picker';
			box.innerHTML = '<div class="ds-field" data-size="sm">' +
				'<label class="ds-label">' + esc(lang('selectGroup')) + '</label>' +
				'<select class="ds-input" data-size="sm"><option value="">' + esc(lang('loading')) + '…</option></select>' +
				'</div>';
			var select = box.querySelector('select');
			cell.appendChild(box);

			var groupsUrl = apiUrl.replace(/\/applications\/\d+.*$/, '/organizations/' + orgId + '/groups');
			fetchJson(groupsUrl).then(function (groups) {
				groups = groups || [];
				if (!groups.length) { renderConflict(cell, err); return; }
				select.innerHTML = '<option value="">' + esc(lang('selectGroup')) + '…</option>' +
					groups.map(function (g) {
						return '<option value="' + esc(g.id) + '">' + esc(g.name) + '</option>';
					}).join('');
				// Selecting a group resubmits the create with group_id set → on
				// success the slot collapses to ✓ + Edit (same as #59).
				select.addEventListener('change', function () {
					var gid = select.value;
					if (!gid) return;
					select.disabled = true;
					btn.disabled = true;
					// Resend WITH the resolved org id too, so the create pins to the
					// same org it first resolved (the chosen group belongs to it) —
					// not a re-resolution that might pick a different org.
					var p = Object.assign({}, params, { group_id: gid, organization_id: orgId });
					postJsonToLegacy(url, p).then(function (result) {
						collapseToEditLink(btn, cell, result);
					}).catch(function (err2) {
						btn.disabled = false;
						if (needsGroup(err2)) {
							renderGroupPicker(cell, btn, params, url, err2);
						} else {
							clearGroupPicker(cell);
							renderConflict(cell, err2);
						}
					});
				});
			}).catch(function () {
				renderConflict(cell, err);
			});
		}

		// Delegated event: date action split button (primary + dropdown items)
		root.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-create]');
			if (!btn || btn.disabled) return;

			var params = dateParamsMap[btn.dataset.dateId];
			if (!params) return;

			var urls = {
				allocation: '/?menuaction=booking.uiallocation.add',
				booking: '/?menuaction=booking.uibooking.add',
				event: '/?menuaction=booking.uievent.add'
			};
			var url = urls[btn.dataset.create];
			if (!url) return;

			// Disable only the clicked button while submitting. The three
			// create actions (allocation/booking/event) for a date are
			// independent, so creating one must not lock the others.
			btn.disabled = true;
			btn.setAttribute('aria-busy', 'true');

			// Clear any overlap message / picker left from a previous attempt.
			var cell = btn.closest('td');
			clearConflict(cell);
			clearGroupPicker(cell);

			postJsonToLegacy(url, params).then(function (result) {
				// Success: the slot is fulfilled — collapse the whole split to a
				// ✓ + in-place Edit link (see collapseToEditLink).
				collapseToEditLink(btn, cell, result);
			}).catch(function (err) {
				// Failure: re-enable so the officer can retry. A multi-group-org
				// booking that needs a group gets an inline group picker; overlaps
				// and other errors render inline — no navigation, no alert
				// (#pe-queue/59, /116).
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
				if (btn.dataset.create === 'booking' && needsGroup(err)) {
					renderGroupPicker(cell, btn, params, url, err);
				} else {
					renderConflict(cell, err);
				}
			});
		});

		// Delegated event: per-date "Avslå" — reject only this sub-application.
		root.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-reject-app]');
			if (!btn) return;
			var pop = btn.closest('.ds-dropdown');
			if (pop && pop.matches(':popover-open')) pop.hidePopover();
			showRejectModal({ appId: parseInt(btn.dataset.rejectApp, 10), single: true });
		});

		// Delegated event: schedule link popup
		root.addEventListener('click', function (e) {
			var link = e.target.closest('.app-show__schedule-link');
			if (!link) return;
			e.preventDefault();
			var dateStr = link.dataset.scheduleDate || '';
			var scheduleUrl = '/?menuaction=bookingfrontend.uibuilding.schedule&id=' + app.building_id + '&backend=1&date=' + dateStr.substring(0, 10);
			window.open(scheduleUrl, '', 'width=1048,height=600,scrollbars=yes');
		});

		// Delegated event: association delete button
		root.addEventListener('click', function (e) {
			var btn = e.target.closest('.app-show__assoc-delete');
			if (!btn) return;
			if (!confirm(lang('deleteAssociationConfirm') !== 'deleteAssociationConfirm' ? lang('deleteAssociationConfirm') : 'Deactivate this association?')) return;
			var assocId = btn.dataset.assocId;
			var assocType = btn.dataset.assocType;
			btn.disabled = true;
			btn.textContent = '...';
			fetch(apiUrl + '/associations/' + assocId, {
				method: 'DELETE',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ type: assocType })
			}).then(function (res) {
				if (!res.ok) throw new Error('HTTP ' + res.status);
				return res.json();
			}).then(function () {
				window.location.reload();
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('delete');
				alert(lang('error') + ': ' + err.message);
			});
		});

		// Delegated event: association activate button
		root.addEventListener('click', function (e) {
			var btn = e.target.closest('.app-show__assoc-activate');
			if (!btn) return;
			if (!confirm(lang('activateAssociationConfirm') !== 'activateAssociationConfirm' ? lang('activateAssociationConfirm') : 'Reactivate this association?')) return;
			var assocId = btn.dataset.assocId;
			var assocType = btn.dataset.assocType;
			btn.disabled = true;
			btn.textContent = '...';
			fetch(apiUrl + '/associations/' + assocId + '/activate', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ type: assocType })
			}).then(function (res) {
				if (!res.ok) throw new Error('HTTP ' + res.status);
				return res.json();
			}).then(function () {
				window.location.reload();
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('activate');
				alert(lang('error') + ': ' + err.message);
			});
		});

		// Documents
		var docs = data.documents || [];
		if (docs.length > 0) {
			var docsHtml = '<table class="ds-table" data-size="sm" data-zebra>' +
				'<thead><tr><th>' + lang('name') + '</th><th>' + lang('category') + '</th></tr></thead><tbody>';
			docs.forEach(function (doc) {
				var nameCell = doc.download_url
					? '<a href="' + esc(doc.download_url) + '">' + esc(doc.name) + '</a>'
					: esc(doc.name);
				docsHtml += '<tr><td>' + nameCell + '</td><td>' + esc(doc.category) + '</td></tr>';
			});
			docsHtml += '</tbody></table>';
			html += section(lang('documents'), docsHtml, { icon: ICONS.doc });
		}

		// Orders (only when articles config is enabled)
		// Aggregate all order lines by article into a single summary table (matches legacy)
		var orders = data.orders || [];
		if (app.activate_application_articles && orders.length > 0) {
			var articleMap = {};
			var grandTotal = 0;
			orders.forEach(function (order) {
				(order.lines || []).forEach(function (line) {
					var key = line.article_mapping_id || line.name;
					if (!articleMap[key]) {
						articleMap[key] = {
							name: line.name,
							unit: line.unit,
							unit_price: 0,
							tax_per_unit: 0,
							quantity: 0,
							total: 0
						};
					}
					articleMap[key].quantity += Number(line.quantity) || 0;
					var lineAmount = Number(line.amount) || 0;
					var lineTax = Number(line.tax) || 0;
					articleMap[key].total += lineAmount + lineTax;
					// Derive unit price from the line (amount / quantity)
					var qty = Number(line.quantity) || 1;
					articleMap[key].unit_price = (lineAmount / qty);
					articleMap[key].tax_per_unit = (lineTax / qty);
					grandTotal += lineAmount + lineTax;
				});
			});

			var articleKeys = Object.keys(articleMap);
			if (articleKeys.length > 0) {
				var ordersHtml = '';
				if (isCombined) {
					ordersHtml += '<p>' + orders.length + ' ' + lang('orders') + '</p>';
				}
				ordersHtml += '<table class="ds-table" data-size="sm" data-zebra>' +
					'<thead><tr><th>' + lang('article') + '</th><th>' + lang('unit') + '</th><th class="app-show__num">' + lang('unitPrice') + '</th><th class="app-show__num">' + lang('tax') + '</th><th class="app-show__num">' + lang('quantity') + '</th><th class="app-show__num">' + lang('sum') + '</th></tr></thead><tbody>';
				articleKeys.forEach(function (key) {
					var a = articleMap[key];
					ordersHtml += '<tr><td>' + esc(a.name) + '</td><td>' + esc(a.unit) + '</td><td class="app-show__num">' + a.unit_price.toFixed(2) + '</td><td class="app-show__num">' + a.tax_per_unit.toFixed(2) + '</td><td class="app-show__num">' + a.quantity + '</td><td class="app-show__num">' + a.total.toFixed(2) + '</td></tr>';
				});
				ordersHtml += '</tbody>' +
					'<tfoot><tr><td colspan="5">' + lang('sum') + ':</td><td class="app-show__num">' + grandTotal.toFixed(2) + '</td></tr></tfoot>' +
					'</table>';
				html += section(lang('orders'), ordersHtml, { icon: ICONS.cart });
			}
		}

		// Associations
		var associations = data.associations || [];
		if (associations.length > 0) {
			var assocHtml = '<table class="ds-table" data-size="sm" data-zebra>' +
				'<thead><tr><th>ID</th><th>' + lang('type') + '</th><th>' + lang('from') + '</th><th>' + lang('to') + '</th><th class="app-show__num">' + lang('cost') + '</th><th>' + lang('active') + '</th>';
			if (isCO) assocHtml += '<th></th>';
			assocHtml += '</tr></thead><tbody>';
			associations.forEach(function (a) {
				var activeLabel = (a.active === 1 || a.active === '1') ? lang('yes') : lang('no');
				var costVal = (a.cost != null && a.cost !== '' && Number(a.cost) !== 0) ? Number(a.cost).toFixed(2) : '—';
				assocHtml += '<tr><td>' + esc(a.id) + '</td><td>' + esc(a.type) + '</td><td>' + fmtDate(a.from_) + '</td><td>' + fmtDate(a.to_) + '</td><td class="app-show__num">' + costVal + '</td><td>' + activeLabel + '</td>';
				if (isCO) {
					if (a.active === 1 || a.active === '1') {
						assocHtml += '<td><button type="button" class="ds-button app-show__assoc-delete" data-variant="primary" data-color="danger" data-size="sm" data-assoc-id="' + esc(a.id) + '" data-assoc-type="' + esc(a.type) + '">' + lang('delete') + '</button></td>';
					} else {
						assocHtml += '<td><button type="button" class="ds-button app-show__assoc-activate" data-variant="secondary" data-color="success" data-size="sm" data-assoc-id="' + esc(a.id) + '" data-assoc-type="' + esc(a.type) + '">' + lang('activate') + '</button></td>';
					}
				}
				assocHtml += '</tr>';
			});
			assocHtml += '</tbody></table>';
			html += section(lang('associations'), assocHtml, { icon: ICONS.link });
		}

		// Recurring info — async loaded section
		if (app.recurring_data) {
			html += '<div id="recurring-section">' +
				section(lang('recurring'),
					'<div class="app-show__loading-inline">' + spinner('sm') + '</div>',
					{ icon: ICONS.repeat }) +
				'</div>';
		}

		document.getElementById('application-details').innerHTML = html;

		// Load recurring preview asynchronously after initial render
		if (app.recurring_data) {
			loadRecurringPreview(app, data);
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// Terms tab
	// ═══════════════════════════════════════════════════════════════════

	function renderTerms(app) {
		var html = '';

		// System-wide terms text (plain text from config textarea, must be escaped)
		if (app.application_terms) {
			html += '<section class="ds-card app-show__card">' + esc(app.application_terms) + '</section>';
		}

		// Regulation documents (building + resource docs)
		var regDocs = app.regulation_documents || [];
		if (regDocs.length > 0) {
			var docsHtml = '<table class="ds-table" data-size="sm" data-zebra>' +
				'<thead><tr><th>' + lang('name') + '</th></tr></thead><tbody>';
			regDocs.forEach(function (doc) {
				docsHtml += '<tr><td><a href="' + esc(doc.download_url) + '">' + esc(doc.display_name) + '</a></td></tr>';
			});
			docsHtml += '</tbody></table>';
			html += section(lang('document'), docsHtml, { icon: ICONS.doc });
		}

		// Footer text
		if (app.application_terms || regDocs.length > 0) {
			html += '<p class="app-show__terms-footer">' + esc(lang('termsFooter')) + '</p>';
		}

		// Per-application agreement requirements — intentionally unescaped.
		// This field is admin-authored rich text (via rich_text_editor), rendered
		// with |raw in legacy Twig and disable-output-escaping in legacy XSL.
		if (app.agreement_requirements) {
			html += section(lang('additionalRequirements'), app.agreement_requirements, { icon: ICONS.doc });
		}

		if (!html) {
			html = '<p class="app-show__empty">' + lang('termsAndConditions') + ': &mdash;</p>';
		}
		document.getElementById('application-terms').innerHTML = html;
	}

	// ═══════════════════════════════════════════════════════════════════
	// Internal notes tab
	// ═══════════════════════════════════════════════════════════════════

	function renderNotes(notes) {
		if (!notes || notes.length === 0) {
			document.getElementById('application-notes').innerHTML =
				'<p class="app-show__empty">' + lang('internalNotes') + ': &mdash;</p>';
			return;
		}
		var html = '';
		notes.forEach(function (note) {
			html += '<div class="app-show__comment">' +
				'<div class="app-show__comment-meta">' +
				esc(note.author_name || 'Unknown') + ' &mdash; ' + fmtDate(note.created) +
				'</div>' +
				'<div class="app-show__comment-text">' + escNl(note.content) + '</div>' +
				'</div>';
		});
		document.getElementById('application-notes').innerHTML = html;
	}

	// ═══════════════════════════════════════════════════════════════════
	// History / comments tab
	// ═══════════════════════════════════════════════════════════════════

	function renderComments(comments) {
		if (!comments || comments.length === 0) {
			document.getElementById('application-comments').innerHTML =
				'<p class="app-show__empty">' + lang('comments') + ': &mdash;</p>';
			return;
		}
		var html = '';
		comments.forEach(function (comment) {
			var typeTag = '';
			if (comment.type && comment.type !== 'comment') {
				typeTag = ' <span class="ds-tag" data-color="neutral">' + esc(comment.type) + '</span>';
			}
			html += '<div class="app-show__comment">' +
				'<div class="app-show__comment-meta">' +
				esc(comment.author || comment.name || 'System') + ' &mdash; ' + fmtDate(comment.time) +
				typeTag +
				'</div>' +
				'<div class="app-show__comment-text">' + escNl(comment.comment) + '</div>' +
				'</div>';
		});
		document.getElementById('application-comments').innerHTML = html;
	}

	// ═══════════════════════════════════════════════════════════════════
	// Recurring preview (async)
	// ═══════════════════════════════════════════════════════════════════

	function loadRecurringPreview(app, data, lastResult) {
		fetchJson(apiUrl + '/recurring-preview').then(function (preview) {
			renderRecurringSection(app, preview, data, lastResult);
		}).catch(function (err) {
			var el = document.getElementById('recurring-section');
			if (el) {
				el.innerHTML = section(lang('recurring'),
					'<p class="app-show__empty">' + lang('error') + ': ' + esc(err.message) + '</p>',
					{ icon: ICONS.repeat });
			}
		});
	}

	function renderRecurringSection(app, preview, data, lastResult) {
		var el = document.getElementById('recurring-section');
		if (!el) return;

		var isCO = app.toolbar && app.toolbar.case_officer_is_current_user;
		var counts = preview.counts || {};
		var items = preview.items || [];
		var season = preview.season_info;
		var html = '';

		// Season info alert
		if (season) {
			html += '<div class="ds-alert app-show__alert" data-color="info">' +
				'<p class="ds-paragraph">' + esc(lang('season')) + ': <strong>' + esc(season.name) + '</strong> (' +
				esc(season.from_) + ' — ' + esc(season.to_) + ')</p>' +
				'</div>';
		}

		// Recurring badges
		html += '<div class="app-show__recurring-badges">';
		html += '<span class="ds-tag" data-color="accent">' +
			esc(lang('interval')) + ': ' + esc(preview.interval_weeks) + ' ' + esc(lang('weeks')) + '</span>';
		if (items.length > 0) {
			html += '<span class="ds-tag" data-color="accent">' +
				esc(items[0].day_name) + '</span>';
		}
		html += '<span class="ds-tag" data-color="neutral">' +
			esc(counts.total) + ' ' + esc(lang('dates')) + '</span>';
		if (counts.existing > 0) {
			html += '<span class="ds-tag" data-color="success">' +
				esc(counts.existing) + ' ' + esc(lang('dateCreated')).replace(/^- | -$/g, '') + '</span>';
		}
		if (counts.conflict > 0) {
			html += '<span class="ds-tag" data-color="danger">' +
				esc(counts.conflict) + ' ' + esc(lang('collision')) + '</span>';
		}

		// Create button — sits on the same row as the tags, pushed to the right
		if (counts.creatable > 0 && isCO) {
			var btnLabel = counts.conflict > 0
				? lang('createNonConflictingAllocations')
				: lang('createAllAllocations');
			html += '<button class="ds-button app-show__recurring-create" data-variant="secondary" type="button" data-color="neutral" data-size="sm" id="recurring-create-btn">' +
				esc(btnLabel) + ' <span class="ds-badge" data-color="neutral" data-count="' + esc(counts.creatable) + '"></span></button>';
		} else if (counts.creatable === 0 && counts.existing > 0) {
			html += '<button type="button" class="ds-button app-show__recurring-create" data-variant="secondary" data-color="neutral" data-size="sm" disabled>' +
				esc(lang('allAllocationsCreated')) + '</button>';
		} else if (!isCO && counts.creatable > 0) {
			html += '<button type="button" class="ds-button app-show__recurring-create" data-variant="secondary" data-color="neutral" data-size="sm" disabled title="' + esc(lang('notCaseOfficerWarning')) + '">' +
				esc(lang('createAllAllocations')) + ' <span class="ds-badge" data-color="neutral" data-count="' + esc(counts.creatable) + '"></span></button>';
		}
		html += '</div>';

		// Show summary from the last create action (persists across re-renders)
		html += '<div id="recurring-summary">';
		if (lastResult) {
			if (lastResult.created && lastResult.created.length > 0) {
				html += '<div class="ds-alert app-show__alert" data-color="success"><p class="ds-paragraph">' +
					esc(lastResult.created.length) + ' ' + esc(lang('successfullyCreated')) + '</p></div>';
			}
			if (lastResult.failed && lastResult.failed.length > 0) {
				html += '<div class="ds-alert app-show__alert" data-color="danger"><p class="ds-paragraph">' +
					esc(lastResult.failed.length) + ' ' + esc(lang('collision')) + '</p></div>';
			}
		}
		html += '</div>';

		// Preview table
		if (items.length > 0) {
			html += '<table class="ds-table" data-size="sm" data-zebra id="recurring-table">' +
				'<thead><tr>' +
				'<th>' + esc(lang('dates')) + '</th>' +
				'<th>' + lang('from') + ' - ' + lang('to') + '</th>' +
				'<th>' + esc(lang('resources')) + '</th>' +
				'<th>' + esc(lang('status')) + '</th>' +
				'<th>' + esc(lang('handling')) + '</th>' +
				'</tr></thead><tbody>';

			items.forEach(function (item) {
				html += '<tr>';
				html += '<td>' + esc(item.day_name) + ' ' + esc(item.date_display) + '</td>';
				html += '<td>' + esc(item.time_display) + '</td>';
				html += '<td>' + esc(item.resource_display) + '</td>';

				// Status column
				if (item.exists) {
					html += '<td><span class="ds-tag" data-color="success">' + esc(lang('dateCreated')).replace(/^- | -$/g, '') + '</span></td>';
				} else if (item.has_conflict) {
					var conflictText = '';
					(item.conflict_details || []).forEach(function (c) {
						if (conflictText) conflictText += ', ';
						conflictText += c.type + (c.name ? ' (' + c.name + ')' : '');
					});
					html += '<td><span class="ds-tag" data-color="danger">' + esc(lang('collision')) + '</span>';
					if (conflictText) {
						html += ' <span class="app-show__conflict-text">' + esc(conflictText) + '</span>';
					}
					html += '</td>';
				} else {
					html += '<td><span class="ds-tag" data-color="neutral">' + esc(lang('notYetCreated')) + '</span></td>';
				}

				// Action column
				if (item.exists) {
					html += '<td><a class="ds-button" data-variant="secondary" data-color="neutral" data-size="sm" href="/?menuaction=booking.uiallocation.edit&id=' + esc(item.allocation_id) + '">' + esc(lang('show')) + '</a></td>';
				} else if (item.has_conflict) {
					html += '<td><a class="ds-button" data-variant="secondary" data-color="neutral" data-size="sm" href="' + esc(item.schedule_link) + '" target="_blank">' + esc(lang('schedule')) + '</a></td>';
				} else {
					html += '<td>&mdash;</td>';
				}

				html += '</tr>';
			});

			html += '</tbody></table>';
		}

		el.innerHTML = section(lang('recurring'), html, { icon: ICONS.repeat });

		// Create button handler
		var createBtn = document.getElementById('recurring-create-btn');
		if (createBtn) {
			createBtn.addEventListener('click', function () {
				createBtn.disabled = true;
				createBtn.textContent = lang('creatingAllocations') + '...';

				postJson(apiUrl + '/create-recurring-allocations').then(function (result) {
					// The accept ("Godta søknaden") gating is derived from the
					// association count captured server-side at page load. Re-fetch the
					// application so the toolbar reflects the allocations just created —
					// otherwise the button stays disabled until a manual reload.
					return fetchJson(apiUrl).then(function (freshApp) {
						if (freshApp && freshApp.toolbar) {
							app.toolbar = freshApp.toolbar;
							app.num_associations = freshApp.num_associations;
							renderToolbar(app);
							renderHeader(app);
						}
					}).catch(function () {
						// Non-fatal: leave the toolbar as-is if the refresh fails.
					}).then(function () {
						// Refresh the section, passing result so the summary persists
						loadRecurringPreview(app, data, result);
					});
				}).catch(function (err) {
					createBtn.disabled = false;
					createBtn.textContent = lang('createAllAllocations');
					alert(lang('error') + ': ' + err.message);
				});
			});
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// Hospitality orders tab
	// ═══════════════════════════════════════════════════════════════════

	var _hospOrdersHospitalities = []; // cached for create modal

	function renderHospitalityOrders(data) {
		var container = document.getElementById('application-hospitality-orders');
		var tabBtn = document.getElementById('tab-btn-hospitality-orders');
		if (!container) return;

		var hospOrdersUrl = root.dataset.hospitalityOrdersUrl;
		if (!hospOrdersUrl) return;
		hospOrdersUrl = hospOrdersUrl.split('?')[0];

		var app = data.application;

		// First: check if any active hospitalities serve this application's resources
		fetchJson(apiUrl + '/hospitalities').then(function (hospitalities) {
			if (!hospitalities || hospitalities.length === 0) {
				// No hospitalities — hide tab entirely
				if (tabBtn) tabBtn.hidden = true;
				return;
			}

			_hospOrdersHospitalities = hospitalities;

			// Show the tab button
			if (tabBtn) tabBtn.hidden = false;

			// Collect all application IDs
			var related = data.related || [];
			var appIds = [];
			if (related.length > 1) {
				related.forEach(function (rel) { appIds.push(rel.id); });
			} else {
				appIds.push(app.id);
			}

			// Build query string
			var queryParts = appIds.map(function (id) {
				return 'application_id[]=' + encodeURIComponent(id);
			});
			var url = hospOrdersUrl + '?' + queryParts.join('&');

			container.innerHTML = '<div class="app-show__loading-inline">' + spinner('sm') + '</div>';

			fetchJson(url).then(function (orders) {
				var html = '';

				// Create order button
				html += '<div class="hosp-show__tab-actions" style="margin-bottom:0.75rem">' +
					'<button type="button" class="ds-button" data-variant="primary" data-color="accent" data-size="sm" data-action="create-hospitality-order">' +
					esc(lang('createOrder')) + '</button></div>';

				html += '<div id="application-hospitality-orders-list"></div>';
				container.innerHTML = html;

				new HospitalityOrderList(document.getElementById('application-hospitality-orders-list'), {
					orders: orders,
					lang: lang,
					columns: { application: false, hospitality: true },
					emptyText: lang('noOrders')
				});
			}).catch(function (err) {
				container.innerHTML = '<p class="app-show__empty">' + esc(lang('error')) + ': ' + esc(err.message) + '</p>';
			});
		}).catch(function () {
			// Endpoint failed — hide tab
			if (tabBtn) tabBtn.hidden = true;
		});
	}

	// Create hospitality order from application page
	root.addEventListener('click', function (e) {
		if (!e.target.closest('[data-action="create-hospitality-order"]')) return;

		var app = null;
		// Extract current application ID from the URL
		var appId = parseInt(root.dataset.applicationId, 10);

		var hospitalityBaseUrl = root.dataset.hospitalityBaseUrl;
		if (hospitalityBaseUrl) hospitalityBaseUrl = hospitalityBaseUrl.split('?')[0];

		var applicationsBaseUrl = root.dataset.applicationsBaseUrl;
		if (applicationsBaseUrl) applicationsBaseUrl = applicationsBaseUrl.split('?')[0];

		var ordersStoreUrl = root.dataset.hospitalityOrdersUrl;
		if (ordersStoreUrl) ordersStoreUrl = ordersStoreUrl.split('?')[0];

		HospitalityOrderModal.open({
			applicationId: appId,
			hospitalities: _hospOrdersHospitalities,
			ordersStoreUrl: ordersStoreUrl,
			applicationsBaseUrl: applicationsBaseUrl,
			deliveryLocationsBaseUrl: hospitalityBaseUrl,
			lang: lang,
			esc: esc,
			fetchJson: fetchJson,
			postJson: postJsonBody,
			showModal: showModal,
			closeModal: closeModal,
			showToast: showToast,
			onSuccess: function () {
				// Re-fetch and re-render the hospitality orders tab
				// We need to pass data again — simplest: just reload
				var container = document.getElementById('application-hospitality-orders');
				if (container) {
					container.innerHTML = '<div class="app-show__loading-inline">' + spinner('sm') + '</div>';
				}
				var hospOrdersUrl = root.dataset.hospitalityOrdersUrl.split('?')[0];
				var related = _hospOrdersHospitalities; // reuse cached
				var appIds = [appId]; // simplified — just main app for refresh
				var queryParts = appIds.map(function (id) {
					return 'application_id[]=' + encodeURIComponent(id);
				});
				fetchJson(hospOrdersUrl + '?' + queryParts.join('&')).then(function (orders) {
					if (container) {
						var html = '<div class="hosp-show__tab-actions" style="margin-bottom:0.75rem">' +
							'<button type="button" class="ds-button" data-variant="primary" data-color="accent" data-size="sm" data-action="create-hospitality-order">' +
							esc(lang('createOrder')) + '</button></div>' +
							'<div id="application-hospitality-orders-list"></div>';
						container.innerHTML = html;
						new HospitalityOrderList(document.getElementById('application-hospitality-orders-list'), {
							orders: orders,
							lang: lang,
							columns: { application: false, hospitality: true },
							emptyText: lang('noOrders')
						});
					}
				});
			}
		});
	});

	// ═══════════════════════════════════════════════════════════════════
	// Modal system
	// ═══════════════════════════════════════════════════════════════════

	function showModal(id, title, bodyHtml, footerHtml) {
		var existing = document.getElementById(id);
		if (existing) existing.remove();

		var dlg = document.createElement('dialog');
		dlg.id = id;
		dlg.className = 'ds-dialog app-show__dialog';
		dlg.innerHTML =
			'<button type="button" class="app-show__dialog-close" data-command="close" data-modal-close aria-label="' + esc(lang('cancel')) + '"></button>' +
			'<div class="ds-dialog__block"><h2 class="ds-heading" data-size="sm">' + esc(title) + '</h2></div>' +
			'<div class="ds-dialog__block">' + bodyHtml + '</div>' +
			'<div class="ds-dialog__block app-show__dialog-footer">' + footerHtml + '</div>';

		document.body.appendChild(dlg);

		// Close handlers: backdrop click + any [data-modal-close]; remove on close.
		dlg.addEventListener('click', function (e) {
			if (e.target === dlg || e.target.closest('[data-modal-close]')) closeModal(id);
		});
		dlg.addEventListener('close', function () { dlg.remove(); });

		if (typeof dlg.showModal === 'function') dlg.showModal();

		// Focus first input
		var firstInput = dlg.querySelector('textarea, select, input');
		if (firstInput) setTimeout(function () { firstInput.focus(); }, 50);

		return dlg;
	}

	function closeModal(id) {
		var dlg = document.getElementById(id);
		if (!dlg) return;
		if (dlg.open) dlg.close(); // 'close' listener removes it
		else dlg.remove();
	}

	// ── Comment modal (reply to applicant) ─────────────────────────────

	function showCommentModal() {
		var body = '<div class="ds-field" data-size="sm">' +
			'<label class="ds-label" for="modal-comment-text">' + esc(lang('writeReplyToApplicant')) + '</label>' +
			'<textarea id="modal-comment-text" class="ds-input" rows="5"></textarea></div>';
		var footer = '<button type="button" class="ds-button" data-variant="secondary" data-color="neutral" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="ds-button" data-variant="primary" data-color="success" id="modal-comment-submit">' + esc(lang('send')) + '</button>';

		showModal('comment-dialog', lang('sendReplyToApplicant'), body, footer);

		document.getElementById('modal-comment-submit').addEventListener('click', function () {
			var text = document.getElementById('modal-comment-text').value.trim();
			if (!text) return;
			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			postJsonBody(apiUrl + '/comment', { comment: text }).then(function () {
				closeModal('comment-dialog');
				showToast(lang('commentSent'));
				setTimeout(function () { window.location.reload(); }, 800);
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('send');
				alert(lang('error') + ': ' + err.message);
			});
		});
	}

	// ── Internal note modal ────────────────────────────────────────────

	function showInternalNoteModal() {
		var body = '<div class="ds-field" data-size="sm">' +
			'<label class="ds-label" for="modal-note-text">' + esc(lang('noteContent')) + '</label>' +
			'<textarea id="modal-note-text" class="ds-input" rows="5"></textarea></div>';
		var footer = '<button type="button" class="ds-button" data-variant="secondary" data-color="neutral" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="ds-button" data-variant="primary" data-color="success" id="modal-note-submit">' + esc(lang('send')) + '</button>';

		showModal('note-dialog', lang('createInternalNote'), body, footer);

		document.getElementById('modal-note-submit').addEventListener('click', function () {
			var text = document.getElementById('modal-note-text').value.trim();
			if (!text) return;
			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			postJsonBody(apiUrl + '/internal-note', { content: text }).then(function () {
				closeModal('note-dialog');
				showToast(lang('noteSaved'));
				setTimeout(function () { window.location.reload(); }, 800);
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('send');
				alert(lang('error') + ': ' + err.message);
			});
		});
	}

	// ── Accept modal ───────────────────────────────────────────────────

	function showAcceptModal() {
		var body = '<div class="ds-field" data-size="sm">' +
			'<label class="ds-label" for="modal-accept-text">' + esc(lang('acceptanceMessage')) + '</label>' +
			'<textarea id="modal-accept-text" class="ds-input" rows="4" placeholder="' + esc(lang('optional')) + '"></textarea></div>' +
			'<div class="ds-field" data-size="sm"><input type="checkbox" class="ds-input" id="modal-accept-email" checked>' +
			'<label class="ds-label" for="modal-accept-email">' + esc(lang('sendEmailToApplicant')) + '</label></div>';
		var footer = '<button type="button" class="ds-button" data-variant="secondary" data-color="neutral" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="ds-button" data-variant="primary" data-color="success" id="modal-accept-submit">' + esc(lang('approve')) + '</button>';

		showModal('accept-dialog', lang('acceptApplication'), body, footer);

		document.getElementById('modal-accept-submit').addEventListener('click', function () {
			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			var message = document.getElementById('modal-accept-text').value.trim();
			var sendEmail = document.getElementById('modal-accept-email').checked;

			postJsonBody(apiUrl + '/accept', { message: message, send_email: sendEmail }).then(function () {
				closeModal('accept-dialog');
				showToast(lang('applicationAccepted'));
				setTimeout(function () { window.location.reload(); }, 800);
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('approve');
				alert(lang('error') + ': ' + err.message);
			});
		});
	}

	// ── Reject modal ───────────────────────────────────────────────────

	// opts.appId + opts.single → reject only that sub-application (no cascade to the
	// combined-cart siblings). No opts → reject this application and its whole group.
	function showRejectModal(opts) {
		opts = opts || {};
		var single = !!opts.single;
		var targetId = opts.appId || null;
		var rejectUrl = targetId
			? apiUrl.replace(/\/\d+$/, '/' + targetId) + '/reject'
			: apiUrl + '/reject';
		var title = single && targetId
			? lang('rejectApplication') + ' #' + targetId
			: lang('rejectApplication');

		var body = '<div class="ds-field" data-size="sm">' +
			'<label class="ds-label" for="modal-reject-text">' + esc(lang('rejectionReason')) + ' *</label>' +
			'<textarea id="modal-reject-text" class="ds-input" rows="4" required></textarea></div>' +
			'<div class="ds-field" data-size="sm"><input type="checkbox" class="ds-input" id="modal-reject-email" checked>' +
			'<label class="ds-label" for="modal-reject-email">' + esc(lang('sendEmailToApplicant')) + '</label></div>';
		var footer = '<button type="button" class="ds-button" data-variant="secondary" data-color="neutral" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="ds-button" data-variant="primary" data-color="danger" id="modal-reject-submit">' + esc(lang('rejectBtn')) + '</button>';

		showModal('reject-dialog', title, body, footer);

		document.getElementById('modal-reject-submit').addEventListener('click', function () {
			var text = document.getElementById('modal-reject-text').value.trim();
			if (!text) {
				document.getElementById('modal-reject-text').focus();
				return;
			}
			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			var sendEmail = document.getElementById('modal-reject-email').checked;

			postJsonBody(rejectUrl, { reason: text, send_email: sendEmail, single: single }).then(function () {
				closeModal('reject-dialog');
				showToast(lang('applicationRejected'));
				setTimeout(function () { window.location.reload(); }, 800);
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('rejectBtn');
				alert(lang('error') + ': ' + err.message);
			});
		});
	}

	// ── Messenger modal ───────────────────────────────────────────────

	function showMessengerModal() {
		var body = '<div class="ds-field" data-size="sm">' +
			'<label class="ds-label" for="modal-messenger-subject">' + esc(lang('subject')) + '</label>' +
			'<input type="text" id="modal-messenger-subject" class="ds-input"></div>' +
			'<div class="ds-field" data-size="sm">' +
			'<label class="ds-label" for="modal-messenger-content">' + esc(lang('message')) + '</label>' +
			'<textarea id="modal-messenger-content" class="ds-input" rows="5"></textarea></div>';
		var footer = '<button type="button" class="ds-button" data-variant="secondary" data-color="neutral" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="ds-button" data-variant="primary" data-color="success" id="modal-messenger-submit">' + esc(lang('send')) + '</button>';

		showModal('messenger-dialog', lang('sendMessageToCaseOfficer'), body, footer);

		document.getElementById('modal-messenger-submit').addEventListener('click', function () {
			var subject = document.getElementById('modal-messenger-subject').value.trim();
			var content = document.getElementById('modal-messenger-content').value.trim();
			if (!content) {
				document.getElementById('modal-messenger-content').focus();
				return;
			}
			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			postJsonBody(apiUrl + '/message', { subject: subject, content: content }).then(function () {
				closeModal('messenger-dialog');
				showToast(lang('messageSent'));
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('send');
				alert(lang('error') + ': ' + err.message);
			});
		});
	}

	// ── Change case officer modal ──────────────────────────────────────

	function showChangeUserModal() {
		var body = '<div class="ds-field" data-size="sm">' +
			'<label class="ds-label" for="modal-user-select">' + esc(lang('selectCaseOfficer')) + '</label>' +
			'<select id="modal-user-select" class="ds-input">' +
			'<option value="">' + esc(lang('loading')) + '...</option></select></div>';
		var footer = '<button type="button" class="ds-button" data-variant="secondary" data-color="neutral" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="ds-button" data-variant="primary" data-color="accent" id="modal-user-submit" disabled>' + esc(lang('send')) + '</button>';

		showModal('user-dialog', lang('changeCaseOfficer'), body, footer);

		var select = document.getElementById('modal-user-select');
		var submitBtn = document.getElementById('modal-user-submit');

		fetchJson(apiUrl + '/user-list').then(function (users) {
			select.innerHTML = '<option value="">-- ' + esc(lang('selectCaseOfficer')) + ' --</option>';
			users.forEach(function (u) {
				select.innerHTML += '<option value="' + esc(u.id) + '">' + esc(u.name) + '</option>';
			});
			submitBtn.disabled = false;
		}).catch(function (err) {
			select.innerHTML = '<option value="">' + esc(lang('error')) + '</option>';
		});

		submitBtn.addEventListener('click', function () {
			var userId = parseInt(select.value, 10);
			if (!userId) return;

			var btn = this;
			btn.disabled = true;
			btn.textContent = '...';

			postJsonBody(apiUrl + '/reassign', { user_id: userId }).then(function () {
				closeModal('user-dialog');
				showToast(lang('caseOfficerChanged'));
				setTimeout(function () { window.location.reload(); }, 800);
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = lang('send');
				alert(lang('error') + ': ' + err.message);
			});
		});
	}

})();
