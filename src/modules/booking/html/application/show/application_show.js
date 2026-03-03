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

	function statusTag(status) {
		var colorMap = {
			'new': 'info', 'pending': 'warning',
			'accepted': 'success', 'rejected': 'danger'
		};
		var color = colorMap[(status || '').toLowerCase()] || 'neutral';
		return '<span class="ds-tag" data-color="' + color + '">' + esc(status) + '</span>';
	}

	function section(title, bodyHtml, opts) {
		opts = opts || {};
		if (opts.hideEmpty && !bodyHtml) return '';
		return '<div class="app-show__section">' +
			'<div class="app-show__section-header"><h3>' + esc(title) + '</h3></div>' +
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
				if (!res.ok) throw { status: res.status, errors: json.errors || {} };
				return json;
			});
		});
	}

	function buildDateParams(app, date, agegroups, audience) {
		var params = {
			from_: date.from_ || '',
			to_: date.to_ || '',
			cost: '0',
			application_id: app.id,
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

		function menuItem(label, opts) {
			opts = opts || {};
			var disabled = opts.disabled;
			var cls = 'app-dropdown__item';
			if (disabled) cls += ' app-show__toolbar-disabled';
			var attrs = disabled ? ' aria-disabled="true" tabindex="-1"' : '';
			if (opts.action) attrs += ' data-action="' + esc(opts.action) + '"';
			if (opts.href && !disabled) {
				return '<a class="' + cls + '" href="' + esc(opts.href) + '"' + attrs + '>' + esc(label) + '</a>';
			}
			return '<button type="button" class="' + cls + '"' + attrs + '>' + esc(label) + '</button>';
		}

		function dropdownGroup(triggerCls, triggerIcon, items) {
			items = items.filter(function (item) { return !!item; });
			var id = 'dd-' + Math.random().toString(36).substr(2, 6);
			var html = '<div class="app-dropdown">';
			html += '<button type="button" class="app-button ' + triggerCls + ' app-button-circle app-show__toolbar-trigger" aria-haspopup="true" aria-expanded="false" data-dropdown-id="' + id + '">';
			html += triggerIcon;
			html += '</button>';
			html += '<ul class="app-dropdown__menu" id="' + id + '">';
			items.forEach(function (item) {
				if (item === 'divider') {
					html += '<li class="app-dropdown__divider"></li>';
				} else {
					html += '<li>' + item + '</li>';
				}
			});
			html += '</ul></div>';
			return html;
		}

		var replyIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 17l-5-5 5-5"/><path d="M4 12h11a4 4 0 010 8h-1"/></svg>';
		var forwardIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>';
		var actionsIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';

		var html = '<div class="app-show__toolbar">';
		html += '<div class="app-show__toolbar-group">';

		// Dropdown 1: Reply (green)
		var replyItems = [];
		replyItems.push(menuItem(lang('sendReplyToApplicant'), { disabled: !isCO, action: 'comment-modal' }));
		replyItems.push(menuItem(lang('sendMessageToCaseOfficer'), {
			disabled: !tb.messenger_enabled || isCO || !hasCO,
			action: 'messenger-modal'
		}));
		replyItems.push(menuItem(lang('createInternalNote'), { disabled: !isCO, action: 'internal-note-modal' }));
		html += dropdownGroup('app-button-success', replyIcon, replyItems);

		// Dropdown 2: Forward (warning/orange)
		var forwardItems = [];
		forwardItems.push(menuItem(lang('changeCaseOfficer'), { action: 'change-user-modal' }));
		html += dropdownGroup('app-button-warning', forwardIcon, forwardItems);

		// Dropdown 3: Actions (primary/blue)
		var actionItems = [];

		if (isCO) {
			actionItems.push(menuItem(lang('unassignMe'), { action: 'unassign' }));
			var dashLabel = tb.display_in_dashboard === 1
				? lang('hideFromDashboard')
				: lang('displayInDashboard');
			actionItems.push(menuItem(dashLabel, { action: 'toggle-dashboard' }));
		} else {
			var assignLabel = hasCO ? lang('reAssignToMe') : lang('assignToMe');
			actionItems.push(menuItem(assignLabel, { action: 'assign' }));
		}

		actionItems.push('divider');

		if (tb.show_reject) {
			actionItems.push(menuItem(lang('rejectApplication'), {
				disabled: !isCO, action: 'reject-modal'
			}));
		}

		if (tb.show_accept) {
			if (tb.num_associations === 0) {
				actionItems.push(menuItem(lang('acceptRequiresAssociations'), { disabled: true }));
			} else {
				actionItems.push(menuItem(lang('acceptApplication'), {
					disabled: !isCO, action: 'accept-modal'
				}));
			}
		}

		actionItems.push('divider');

		if (tb.external_archive && !tb.external_archive_key) {
			actionItems.push(menuItem(lang('pdfExportToArchive'), { disabled: !isCO, action: 'export-pdf' }));
			actionItems.push(menuItem(lang('preview'), { disabled: !isCO, action: 'export-pdf-preview' }));
		}

		actionItems.push(menuItem(lang('edit'), { disabled: !isCO, href: tb.edit_url }));

		if (tb.show_edit_selection) {
			actionItems.push(menuItem(lang('editInvoicing'), { disabled: !isCO, href: tb.edit_invoicing_url }));
		}

		actionItems.push(menuItem(lang('backToDashboard'), { href: tb.dashboard_url }));
		actionItems.push(menuItem(lang('backToOverview'), { href: tb.applications_url }));
		html += dropdownGroup('app-button-primary', actionsIcon, actionItems);

		html += '</div></div>';

		renderToolbar._toolbarHtml = html;

		var warningHtml = '';
		if (!isCO) {
			var warningIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> ';
			if (!hasCO) {
				warningHtml += '<div class="app-show__case-officer-warning" role="alert">';
				warningHtml += warningIcon;
				warningHtml += lang('noCaseOfficerWarning');
				warningHtml += '</div>';
			} else {
				warningHtml += '<div class="app-show__case-officer-warning" role="alert">';
				warningHtml += warningIcon;
				warningHtml += lang('differentCaseOfficerWarning');
				warningHtml += '</div>';
			}
		}
		document.getElementById('application-toolbar').innerHTML = warningHtml;

		// Dropdown toggle
		root.addEventListener('click', function (e) {
			var trigger = e.target.closest('.app-show__toolbar-trigger');
			if (trigger) {
				e.stopPropagation();
				var dd = trigger.closest('.app-dropdown');
				var wasOpen = dd.classList.contains('show');
				root.querySelectorAll('.app-dropdown.show').forEach(function (d) {
					d.classList.remove('show');
					d.querySelector('.app-show__toolbar-trigger').setAttribute('aria-expanded', 'false');
				});
				if (!wasOpen) {
					dd.classList.add('show');
					trigger.setAttribute('aria-expanded', 'true');
				}
				return;
			}
			root.querySelectorAll('.app-dropdown.show').forEach(function (d) {
				d.classList.remove('show');
				d.querySelector('.app-show__toolbar-trigger').setAttribute('aria-expanded', 'false');
			});
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				root.querySelectorAll('.app-dropdown.show').forEach(function (d) {
					d.classList.remove('show');
					d.querySelector('.app-show__toolbar-trigger').setAttribute('aria-expanded', 'false');
				});
			}
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
			'<h1 class="app-show__title">' + titleText + '</h1>' +
			statusTag(app.status) +
			'</div>' +
			toolbarHtml +
			'</div>';

		html += '<div class="app-show__meta">';
		html += '<span class="app-show__meta-item">' + lang('building') + ': ' + esc(app.building_name);
		if (app.building_id) {
			html += ' <a href="javascript:void(0)" class="app-show__schedule-link" data-building-schedule="' + esc(app.building_id) + '" title="' + lang('schedule') + '">' +
				'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
				'</a>';
		}
		html += '</span>';
		html += '<span class="app-show__meta-item">' + lang('created') + ': ' + fmtDate(app.created) + '</span>';
		if (app.modified) {
			html += '<span class="app-show__meta-item">' + lang('modified') + ': ' + fmtDate(app.modified) + '</span>';
		}
		html += '<span class="app-show__meta-item">' + lang('caseOfficer') + ': ';
		if (app.case_officer_name) {
			html += esc(app.case_officer_name);
			if (app.case_officer_is_current_user) {
				html += ' <span class="ds-tag" data-color="success">&#10003;</span>';
			}
		} else {
			html += '<em>' + lang('notAssigned') + '</em>';
		}
		html += '</span>';
		if (app.related_application_count > 1) {
			html += '<span class="app-show__meta-item">' + lang('relatedApps') + ': ' + app.related_application_count + '</span>';
		}
		if (app.num_associations > 0) {
			html += '<span class="app-show__meta-item">' + lang('associations') + ': ' + app.num_associations + '</span>';
		}
		if (app.toolbar && app.toolbar.external_archive_key) {
			html += '<span class="app-show__meta-item">' + lang('archiveKey') + ': ' + esc(app.toolbar.external_archive_key) + '</span>';
		}
		html += '</div>';

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
				relHtml += '<div class="app-show__app-card' + (isCurrent ? ' app-show__app-card--current' : '') + '">';
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
					relHtml += '<a class="app-button app-button-sm" href="' + esc(editUrl) + '">' + lang('edit') + '</a>';
				} else {
					relHtml += '<span class="app-button app-button-sm app-show__toolbar-disabled" aria-disabled="true">' + lang('edit') + '</span>';
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
		html += section(lang('contact'), contactHtml);

		// Organization
		if (app.organization) {
			var orgHtml = '';
			orgHtml += field(lang('name'), app.organization.name);
			orgHtml += field(lang('orgNumber'), app.organization.organization_number);
			if (app.organization.in_tax_register != null) {
				orgHtml += field(lang('inTaxRegister'),
					(app.organization.in_tax_register === 1 || app.organization.in_tax_register === '1') ? lang('yes') : lang('no'));
			}
			html += section(lang('organization'), orgHtml);
		} else if (app.customer_identifier_type === 'ssn' && app.customer_ssn) {
			var ssnHtml = field(lang('ssn'), app.customer_ssn.substring(0, 6) + '*****');
			html += section(lang('invoice'), ssnHtml);
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
			html += section(lang('invoice'), invoiceHtml);
		}

		// Event details, audience, agegroups (hidden for simple bookings)
		if (!app.simple) {
			var eventHtml = '';
			eventHtml += field(lang('activity'), app.activity_name);
			eventHtml += field(lang('eventName'), app.name);
			eventHtml += fieldHtml(lang('description'), app.description ? escNl(app.description) : '');
			eventHtml += fieldHtml(lang('equipment'), app.equipment ? escNl(app.equipment) : '');
			eventHtml += field(lang('organizer'), app.organizer);
			if (app.homepage) {
				eventHtml += fieldHtml(lang('homepage'), '<a href="' + esc(app.homepage) + '" target="_blank">' + esc(app.homepage) + '</a>');
			}
			html += section(lang('description'), eventHtml);

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
						'<p>' + audienceNames.map(esc).join(', ') + '</p>');
				}
			}

			var rawAgegroups = data.agegroups || [];

			function renderAgTable(groups) {
				var tbl = '<table class="ds-table" data-border>' +
					'<thead><tr><th>' + lang('name') + '</th><th>' + lang('male') + '</th><th>' + lang('female') + '</th></tr></thead><tbody>';
				var hasData = false;
				(groups || []).forEach(function (ag) {
					var m = parseInt(ag.male || 0);
					var f = parseInt(ag.female || 0);
					if (m > 0 || f > 0) {
						hasData = true;
						tbl += '<tr><td>' + esc(ag.name) + '</td><td>' + m + '</td><td>' + f + '</td></tr>';
					}
				});
				tbl += '</tbody></table>';
				return hasData ? tbl : '';
			}

			if (rawAgegroups.combined) {
				// Combined application — per-app or single depending on all_same
				if (rawAgegroups.all_same) {
					var agContent = renderAgTable((rawAgegroups.per_app[0] || {}).agegroups || []);
					if (agContent) html += section(lang('participants'), agContent);
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
					if (agContent) html += section(lang('participants'), agContent);
				}
			} else if (rawAgegroups.length > 0) {
				// Single application — flat array
				var agContent = renderAgTable(rawAgegroups);
				if (agContent) html += section(lang('participants'), agContent);
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

		if (dates.length > 0 && !isRecurring) {
			var datesHtml = '<table class="ds-table" data-border id="dates-table"><thead><tr>';
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

				// Action dropdown
				var selectHtml = '<select class="app-show__date-select" data-date-id="' + d.id + '"';
				if (!isCO || hasAssoc) selectHtml += ' disabled';
				selectHtml += '>';
				if (hasAssoc) {
					selectHtml += '<option>' + lang('dateCreated') + '</option>';
				} else {
					selectHtml += '<option>' + lang('dateActions') + '</option>';
					selectHtml += '<option>' + lang('createAllocation') + '</option>';
					selectHtml += '<option>' + lang('createBooking') + '</option>';
					selectHtml += '<option>' + lang('createEvent') + '</option>';
				}
				selectHtml += '</select>';

				datesHtml += '<tr>';
				if (isCombined) datesHtml += '<td>#' + esc(d.application_id) + '</td>';
				datesHtml += '<td>' + fmtDate(d.from_) + '</td><td>' + fmtDate(d.to_) + '</td><td>' + esc(d.resource_names) + '</td><td>' + collisionTag + scheduleIcon + '</td><td>' + selectHtml + '</td></tr>';
			});
			datesHtml += '</tbody></table>';
			html += section(lang('dates'), datesHtml);
		}

		// Delegated event: date action dropdown
		root.addEventListener('change', function (e) {
			var sel = e.target.closest('.app-show__date-select');
			if (!sel) return;
			var idx = sel.selectedIndex;
			if (idx === 0) return;

			var dateId = sel.dataset.dateId;
			var params = dateParamsMap[dateId];
			if (!params) return;

			var urls = {
				1: '/?menuaction=booking.uiallocation.add',
				2: '/?menuaction=booking.uibooking.add',
				3: '/?menuaction=booking.uievent.add'
			};
			var url = urls[idx];
			if (!url) return;

			sel.disabled = true;
			postJsonToLegacy(url, params).then(function (result) {
				// Redirect to the edit page for the newly created entity
				window.location.href = result.edit_url;
			}).catch(function (err) {
				sel.disabled = false;
				sel.selectedIndex = 0;
				var msg = lang('error');
				if (err.errors) {
					var errMsgs = Object.values(err.errors).filter(Boolean);
					if (errMsgs.length) msg += ': ' + errMsgs.join(', ');
				}
				alert(msg);
			});
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

		// Documents
		var docs = data.documents || [];
		if (docs.length > 0) {
			var docsHtml = '<table class="ds-table" data-border>' +
				'<thead><tr><th>' + lang('name') + '</th><th>' + lang('category') + '</th></tr></thead><tbody>';
			docs.forEach(function (doc) {
				var nameCell = doc.download_url
					? '<a href="' + esc(doc.download_url) + '">' + esc(doc.name) + '</a>'
					: esc(doc.name);
				docsHtml += '<tr><td>' + nameCell + '</td><td>' + esc(doc.category) + '</td></tr>';
			});
			docsHtml += '</tbody></table>';
			html += section(lang('documents'), docsHtml);
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
				ordersHtml += '<table class="ds-table" data-border>' +
					'<thead><tr><th>' + lang('article') + '</th><th>' + lang('unit') + '</th><th>' + lang('unitPrice') + '</th><th>' + lang('tax') + '</th><th>' + lang('quantity') + '</th><th>' + lang('sum') + '</th></tr></thead><tbody>';
				articleKeys.forEach(function (key) {
					var a = articleMap[key];
					ordersHtml += '<tr><td>' + esc(a.name) + '</td><td>' + esc(a.unit) + '</td><td>' + a.unit_price.toFixed(2) + '</td><td>' + a.tax_per_unit.toFixed(2) + '</td><td>' + a.quantity + '</td><td>' + a.total.toFixed(2) + '</td></tr>';
				});
				ordersHtml += '</tbody>' +
					'<tfoot><tr><td colspan="5">' + lang('sum') + ':</td><td>' + grandTotal.toFixed(2) + '</td></tr></tfoot>' +
					'</table>';
				html += section(lang('orders'), ordersHtml);
			}
		}

		// Associations
		var associations = data.associations || [];
		if (associations.length > 0) {
			var assocHtml = '<table class="ds-table" data-border>' +
				'<thead><tr><th>ID</th><th>' + lang('type') + '</th><th>' + lang('from') + '</th><th>' + lang('to') + '</th><th>' + lang('cost') + '</th><th>' + lang('active') + '</th>';
			if (isCO) assocHtml += '<th></th>';
			assocHtml += '</tr></thead><tbody>';
			associations.forEach(function (a) {
				var activeLabel = (a.active === 1 || a.active === '1') ? lang('yes') : lang('no');
				var costVal = (a.cost != null && a.cost !== '' && Number(a.cost) !== 0) ? Number(a.cost).toFixed(2) : '—';
				assocHtml += '<tr><td>' + esc(a.id) + '</td><td>' + esc(a.type) + '</td><td>' + fmtDate(a.from_) + '</td><td>' + fmtDate(a.to_) + '</td><td>' + costVal + '</td><td>' + activeLabel + '</td>';
				if (isCO) {
					if (a.active === 1 || a.active === '1') {
						assocHtml += '<td><button type="button" class="app-button app-button-sm app-button-danger app-show__assoc-delete" data-assoc-id="' + esc(a.id) + '" data-assoc-type="' + esc(a.type) + '">' + lang('delete') + '</button></td>';
					} else {
						assocHtml += '<td></td>';
					}
				}
				assocHtml += '</tr>';
			});
			assocHtml += '</tbody></table>';
			html += section(lang('associations'), assocHtml);
		}

		// Recurring info — async loaded section
		if (app.recurring_data) {
			html += '<div id="recurring-section">' +
				'<div class="app-show__section">' +
				'<div class="app-show__section-header"><h3>' + esc(lang('recurring')) + '</h3></div>' +
				'<div class="app-show__section-body">' +
				'<div class="app-show__loading-inline"><div class="app-show__spinner"></div></div>' +
				'</div></div></div>';
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
			html += '<div class="app-show__section"><div class="app-show__section-body">' + esc(app.application_terms) + '</div></div>';
		}

		// Regulation documents (building + resource docs)
		var regDocs = app.regulation_documents || [];
		if (regDocs.length > 0) {
			var docsHtml = '<ul class="app-show__doc-list">';
			regDocs.forEach(function (doc) {
				docsHtml += '<li><a href="' + esc(doc.download_url) + '">' + esc(doc.display_name) + '</a></li>';
			});
			docsHtml += '</ul>';
			html += section(lang('document'), docsHtml);
		}

		// Footer text
		if (app.application_terms || regDocs.length > 0) {
			html += '<p class="app-show__terms-footer">' + esc(lang('termsFooter')) + '</p>';
		}

		// Per-application agreement requirements — intentionally unescaped.
		// This field is admin-authored rich text (via rich_text_editor), rendered
		// with |raw in legacy Twig and disable-output-escaping in legacy XSL.
		if (app.agreement_requirements) {
			html += '<div class="app-show__section">' +
				'<div class="app-show__section-header"><h3>' + lang('additionalRequirements') + '</h3></div>' +
				'<div class="app-show__section-body">' + app.agreement_requirements + '</div></div>';
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
				el.innerHTML = '<div class="app-show__section">' +
					'<div class="app-show__section-header"><h3>' + esc(lang('recurring')) + '</h3></div>' +
					'<div class="app-show__section-body"><p class="app-show__empty">' +
					lang('error') + ': ' + esc(err.message) + '</p></div></div>';
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
			html += '<div class="app-show__recurring-season-alert">' +
				'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg> ' +
				'<span>' + esc(lang('season')) + ': <strong>' + esc(season.name) + '</strong> (' +
				esc(season.from_) + ' — ' + esc(season.to_) + ')</span>' +
				'</div>';
		}

		// Recurring badges
		html += '<div class="app-show__recurring-badges">';
		html += '<span class="ds-tag" data-color="neutral">' +
			esc(lang('interval')) + ': ' + esc(preview.interval_weeks) + ' ' + esc(lang('weeks')) + '</span>';
		if (items.length > 0) {
			html += '<span class="ds-tag" data-color="neutral">' +
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
		html += '</div>';

		// Create button
		html += '<div class="app-show__recurring-actions">';
		if (counts.creatable > 0 && isCO) {
			var btnLabel = counts.conflict > 0
				? lang('createNonConflictingAllocations')
				: lang('createAllAllocations');
			html += '<button type="button" class="app-button app-button-success" id="recurring-create-btn">' +
				esc(btnLabel) + ' <span class="app-show__badge">' + esc(counts.creatable) + '</span></button>';
		} else if (counts.creatable === 0 && counts.existing > 0) {
			html += '<button type="button" class="app-button" disabled>' +
				esc(lang('allAllocationsCreated')) + '</button>';
		} else if (!isCO && counts.creatable > 0) {
			html += '<button type="button" class="app-button" disabled title="' + esc(lang('notCaseOfficerWarning')) + '">' +
				esc(lang('createAllAllocations')) + ' <span class="app-show__badge">' + esc(counts.creatable) + '</span></button>';
		}
		// Show summary from the last create action (persists across re-renders)
		html += '<div id="recurring-summary">';
		if (lastResult) {
			html += '<div class="app-show__recurring-summary">';
			if (lastResult.created && lastResult.created.length > 0) {
				html += '<div class="app-show__recurring-summary--success">' +
					esc(lastResult.created.length) + ' ' + esc(lang('successfullyCreated')) + '</div>';
			}
			if (lastResult.failed && lastResult.failed.length > 0) {
				html += '<div class="app-show__recurring-summary--warning">' +
					esc(lastResult.failed.length) + ' ' + esc(lang('collision')) + '</div>';
			}
			html += '</div>';
		}
		html += '</div>';
		html += '</div>';

		// Preview table
		if (items.length > 0) {
			html += '<table class="ds-table" data-border id="recurring-table">' +
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
					html += '<td><a class="app-button app-button-sm" href="/?menuaction=booking.uiallocation.edit&id=' + esc(item.allocation_id) + '">' + esc(lang('show')) + '</a></td>';
				} else if (item.has_conflict) {
					html += '<td><a class="app-button app-button-sm" href="' + esc(item.schedule_link) + '" target="_blank">' + esc(lang('schedule')) + '</a></td>';
				} else {
					html += '<td>&mdash;</td>';
				}

				html += '</tr>';
			});

			html += '</tbody></table>';
		}

		el.innerHTML = '<div class="app-show__section">' +
			'<div class="app-show__section-header"><h3>' + esc(lang('recurring')) + '</h3></div>' +
			'<div class="app-show__section-body">' + html + '</div></div>';

		// Create button handler
		var createBtn = document.getElementById('recurring-create-btn');
		if (createBtn) {
			createBtn.addEventListener('click', function () {
				createBtn.disabled = true;
				createBtn.textContent = lang('creatingAllocations') + '...';

				postJson(apiUrl + '/create-recurring-allocations').then(function (result) {
					// Refresh the section, passing result so the summary persists
					loadRecurringPreview(app, data, result);
				}).catch(function (err) {
					createBtn.disabled = false;
					createBtn.textContent = lang('createAllAllocations');
					alert(lang('error') + ': ' + err.message);
				});
			});
		}
	}

	// ═══════════════════════════════════════════════════════════════════
	// Modal system
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

		// Show
		requestAnimationFrame(function () {
			modal.classList.add('show');
		});

		// Close handlers
		modal.addEventListener('click', function (e) {
			if (e.target === modal || e.target.closest('[data-modal-close]')) {
				closeModal(id);
			}
		});
		modal.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') closeModal(id);
		});

		// Focus first input
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

	// ── Comment modal (reply to applicant) ─────────────────────────────

	function showCommentModal() {
		var body = '<label class="app-show__modal-label" for="modal-comment-text">' + esc(lang('writeReplyToApplicant')) + '</label>' +
			'<textarea id="modal-comment-text" class="app-show__modal-textarea" rows="5"></textarea>';
		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-success" id="modal-comment-submit">' + esc(lang('send')) + '</button>';

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
		var body = '<label class="app-show__modal-label" for="modal-note-text">' + esc(lang('noteContent')) + '</label>' +
			'<textarea id="modal-note-text" class="app-show__modal-textarea" rows="5"></textarea>';
		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-success" id="modal-note-submit">' + esc(lang('send')) + '</button>';

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
		var body = '<label class="app-show__modal-label" for="modal-accept-text">' + esc(lang('acceptanceMessage')) + '</label>' +
			'<textarea id="modal-accept-text" class="app-show__modal-textarea" rows="4" placeholder="' + esc(lang('optional')) + '"></textarea>' +
			'<label class="app-show__modal-checkbox"><input type="checkbox" id="modal-accept-email" checked> ' + esc(lang('sendEmailToApplicant')) + '</label>';
		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-success" id="modal-accept-submit">' + esc(lang('approve')) + '</button>';

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

	function showRejectModal() {
		var body = '<label class="app-show__modal-label" for="modal-reject-text">' + esc(lang('rejectionReason')) + ' *</label>' +
			'<textarea id="modal-reject-text" class="app-show__modal-textarea" rows="4" required></textarea>' +
			'<label class="app-show__modal-checkbox"><input type="checkbox" id="modal-reject-email" checked> ' + esc(lang('sendEmailToApplicant')) + '</label>';
		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-danger" id="modal-reject-submit">' + esc(lang('rejectBtn')) + '</button>';

		showModal('reject-dialog', lang('rejectApplication'), body, footer);

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

			postJsonBody(apiUrl + '/reject', { reason: text, send_email: sendEmail }).then(function () {
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
		var body = '<label class="app-show__modal-label" for="modal-messenger-subject">' + esc(lang('subject')) + '</label>' +
			'<input type="text" id="modal-messenger-subject" class="app-show__modal-input">' +
			'<label class="app-show__modal-label" for="modal-messenger-content">' + esc(lang('message')) + '</label>' +
			'<textarea id="modal-messenger-content" class="app-show__modal-textarea" rows="5"></textarea>';
		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-success" id="modal-messenger-submit">' + esc(lang('send')) + '</button>';

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
		var body = '<p>' + esc(lang('selectCaseOfficer')) + '</p>' +
			'<select id="modal-user-select" class="app-show__modal-select">' +
			'<option value="">' + esc(lang('loading')) + '...</option></select>';
		var footer = '<button type="button" class="app-button" data-modal-close>' + esc(lang('cancel')) + '</button>' +
			'<button type="button" class="app-button app-button-primary" id="modal-user-submit" disabled>' + esc(lang('send')) + '</button>';

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
