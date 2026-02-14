(function () {
	'use strict';

	var lookupCache = {};

	function escapeHtml(str) {
		if (!str && str !== 0) return '';
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(String(str)));
		return div.innerHTML;
	}

	function resolveTemplate(template, data) {
		return template.replace(/\{(\w+)\}/g, function (_, key) {
			var val = data[key];
			return val != null ? encodeURIComponent(val) : '';
		});
	}

	// ------------------------------------------------------------------
	// Built-in render factories
	// ------------------------------------------------------------------
	var render = {
		link: function (opts) {
			var urlTpl = opts.url;
			var target = opts.target || '';
			return function (data, type, row) {
				if (type !== 'display') return data != null ? data : '';
				var url = resolveTemplate(urlTpl, row);
				var targetAttr = target ? ' target="' + escapeHtml(target) + '"' : '';
				return '<a href="' + escapeHtml(url) + '"' + targetAttr + '>' + escapeHtml(data) + '</a>';
			};
		},

		lookup: function (opts) {
			var source = opts.source;
			var valueKey = opts.valueKey || 'value';
			var labelKey = opts.labelKey || 'label';
			var map = null;
			var pending = null;

			function load() {
				if (map) return Promise.resolve(map);
				if (pending) return pending;
				if (lookupCache[source]) {
					map = lookupCache[source];
					return Promise.resolve(map);
				}
				pending = fetch(source, {credentials: 'same-origin'})
					.then(function (r) {
						if (!r.ok) throw new Error('HTTP ' + r.status);
						return r.json();
					})
					.then(function (items) {
						map = {};
						items.forEach(function (item) {
							map[item[valueKey]] = item[labelKey];
						});
						lookupCache[source] = map;
						pending = null;
						return map;
					})
					.catch(function () {
						pending = null;
						map = {};
						return map;
					});
				return pending;
			}

			var renderer = function (data, type) {
				if (type !== 'display') return data != null ? data : '';
				if (!map) return escapeHtml(data);
				return escapeHtml(map[data] || data || '');
			};

			renderer._load = load;
			return renderer;
		},

		date: function (opts) {
			var locale = (opts && opts.locale) || 'nb-NO';
			return function (data, type) {
				if (type !== 'display' || !data) return data || '';
				try {
					return new Date(data).toLocaleDateString(locale);
				} catch (e) {
					return escapeHtml(data);
				}
			};
		},

		html: function () {
			return function (data) {
				return data != null ? data : '';
			};
		}
	};

	// ------------------------------------------------------------------
	// Alert system
	// ------------------------------------------------------------------
	function createAlertSystem(container) {
		var successEl = document.createElement('div');
		successEl.className = 'app-datatable__alert app-datatable__alert--success is-hidden';
		var dangerEl = document.createElement('div');
		dangerEl.className = 'app-datatable__alert app-datatable__alert--danger is-hidden';
		var messageEl = document.createElement('div');
		messageEl.className = 'app-datatable__message';
		container.appendChild(successEl);
		container.appendChild(dangerEl);
		container.appendChild(messageEl);

		var timer = null;

		function hideAll() {
			successEl.classList.add('is-hidden');
			successEl.classList.remove('is-fading');
			dangerEl.classList.add('is-hidden');
			dangerEl.classList.remove('is-fading');
		}

		function show(type, msg) {
			if (timer) clearTimeout(timer);
			hideAll();
			var el = type === 'success' ? successEl : dangerEl;
			el.textContent = msg;
			el.classList.remove('is-hidden', 'is-fading');
			timer = setTimeout(function () {
				el.classList.add('is-fading');
				setTimeout(function () {
					el.classList.add('is-hidden');
				}, 300);
			}, 4000);
		}

		function message(msg) {
			messageEl.innerHTML = msg;
			if (msg) {
				setTimeout(function () { messageEl.innerHTML = ''; }, 3000);
			}
		}

		return {show: show, hideAll: hideAll, message: message};
	}

	// ------------------------------------------------------------------
	// Filter system
	// ------------------------------------------------------------------
	function buildFilters(container, filters, config) {
		if (!filters || !filters.length) return null;

		var stateKey = config.stateKey;
		var collapsible = config.filtersCollapsible !== false;
		var filterLang = config.filterLang || {};

		// Wrapper for collapsible
		var wrapper = document.createElement('div');
		wrapper.className = 'app-datatable__filter-wrapper';

		// Active filters display
		var activeFiltersEl = document.createElement('div');
		activeFiltersEl.className = 'app-datatable__active-filters';
		wrapper.appendChild(activeFiltersEl);

		// Toolbar with toggle + reset
		var toolbar = document.createElement('div');
		toolbar.className = 'app-datatable__filter-toolbar';

		if (collapsible) {
			var toggleBtn = document.createElement('button');
			toggleBtn.type = 'button';
			toggleBtn.className = 'ds-button';
			toggleBtn.setAttribute('data-variant', 'secondary');
			toggleBtn.setAttribute('data-size', 'sm');
			toggleBtn.textContent = filterLang.filter || 'Filter';
			toolbar.appendChild(toggleBtn);
		}

		var resetBtn = document.createElement('button');
		resetBtn.type = 'button';
		resetBtn.className = 'ds-button app-datatable__reset-btn is-hidden';
		resetBtn.setAttribute('data-variant', 'tertiary');
		resetBtn.setAttribute('data-size', 'sm');
		resetBtn.textContent = filterLang.resetFilter || 'Reset filter';
		toolbar.appendChild(resetBtn);
		wrapper.appendChild(toolbar);

		// Filter panel
		var filtersDiv = document.createElement('div');
		filtersDiv.className = 'app-datatable__filters';
		if (collapsible) {
			filtersDiv.classList.add('app-datatable__filters--collapsed');
		}

		var state = {};
		if (stateKey) {
			try {
				state = JSON.parse(sessionStorage.getItem(stateKey + '_filters') || '{}');
			} catch (e) { /* ignore */ }
		}

		var inputs = [];

		filters.forEach(function (f) {
			var group = document.createElement('div');
			group.className = 'app-datatable__filter-group';

			var label = document.createElement('label');
			label.textContent = f.label;
			label.setAttribute('for', 'filter-' + f.name);
			group.appendChild(label);

			var input;
			var savedVal = state[f.name];

			if (f.type === 'select') {
				input = document.createElement('select');
				input.className = 'ds-select';
				input.id = 'filter-' + f.name;
				if (f.multiple) input.multiple = true;
				(f.options || []).forEach(function (opt) {
					var option = document.createElement('option');
					option.value = opt.value;
					option.textContent = opt.label;
					if (savedVal != null ? String(opt.value) === String(savedVal) : opt.selected) {
						option.selected = true;
					}
					input.appendChild(option);
				});
			} else if (f.type === 'checkbox') {
				input = document.createElement('input');
				input.className = 'ds-input';
				input.type = 'checkbox';
				input.id = 'filter-' + f.name;
				input.checked = savedVal != null ? !!savedVal : !!f.checked;
			} else if (f.type === 'date') {
				input = document.createElement('input');
				input.className = 'ds-input';
				input.type = 'date';
				input.id = 'filter-' + f.name;
				input.value = savedVal || f.value || '';
			} else {
				input = document.createElement('input');
				input.className = 'ds-input';
				input.type = 'text';
				input.id = 'filter-' + f.name;
				input.placeholder = f.placeholder || '';
				input.value = savedVal || f.value || '';
			}

			input.dataset.filterName = f.name;
			input.dataset.filterColumn = f.column != null ? f.column : '';
			input.title = f.label || '';
			group.appendChild(input);
			filtersDiv.appendChild(group);
			inputs.push({el: input, config: f});
		});

		wrapper.appendChild(filtersDiv);
		container.appendChild(wrapper);

		// Auto-expand if few filters
		if (collapsible && filters.length < 5) {
			filtersDiv.classList.remove('app-datatable__filters--collapsed');
		}

		if (collapsible && toggleBtn) {
			toggleBtn.addEventListener('click', function () {
				filtersDiv.classList.toggle('app-datatable__filters--collapsed');
			});
		}

		function getValues() {
			var vals = {};
			inputs.forEach(function (inp) {
				var el = inp.el;
				var name = inp.config.name;
				if (el.type === 'checkbox') {
					vals[name] = el.checked;
				} else {
					vals[name] = el.value;
				}
			});
			return vals;
		}

		function saveState() {
			if (!stateKey) return;
			try {
				sessionStorage.setItem(stateKey + '_filters', JSON.stringify(getValues()));
			} catch (e) { /* ignore */ }
		}

		function getActiveFilterNames() {
			var vals = getValues();
			var names = [];
			inputs.forEach(function (inp) {
				var v = vals[inp.config.name];
				var label = inp.config.label || inp.config.name;
				if (inp.config.type === 'checkbox') {
					if (v) names.push(label);
				} else if (inp.config.type === 'select') {
					var firstOpt = inp.config.options && inp.config.options[0];
					if (v && firstOpt && String(v) !== String(firstOpt.value)) names.push(label);
				} else {
					if (v) names.push(label);
				}
			});
			return names;
		}

		function updateIndicator() {
			var names = getActiveFilterNames();
			if (names.length > 0) {
				activeFiltersEl.textContent = (filterLang.activeFilters || 'Active filters') + ': ' + names.join(', ');
				resetBtn.classList.remove('is-hidden');
			} else {
				activeFiltersEl.textContent = '';
				resetBtn.classList.add('is-hidden');
			}
		}

		function reset() {
			inputs.forEach(function (inp) {
				var el = inp.el;
				if (el.type === 'checkbox') {
					el.checked = !!inp.config.checked;
				} else if (el.tagName === 'SELECT') {
					el.selectedIndex = 0;
				} else {
					el.value = inp.config.value || '';
				}
			});
			saveState();
			updateIndicator();
		}

		resetBtn.addEventListener('click', function () {
			reset();
			wrapper.dispatchEvent(new Event('filter-change'));
		});

		inputs.forEach(function (inp) {
			var evtType = inp.el.type === 'checkbox' || inp.el.tagName === 'SELECT' ? 'change' : 'input';
			inp.el.addEventListener(evtType, function () {
				saveState();
				updateIndicator();
				wrapper.dispatchEvent(new Event('filter-change'));
			});
		});

		updateIndicator();

		return {
			element: wrapper,
			getValues: getValues,
			getActiveFilterNames: getActiveFilterNames,
			inputs: inputs,
			reset: reset,
			showResetBtn: function () { resetBtn.classList.remove('is-hidden'); },
			hideResetBtn: function () { resetBtn.classList.add('is-hidden'); }
		};
	}

	// ------------------------------------------------------------------
	// Row actions builder
	// ------------------------------------------------------------------
	function buildActionsColumn(rowActions) {
		return {
			data: null,
			title: '',
			orderable: false,
			searchable: false,
			className: 'app-datatable__actions-cell',
			render: function (data, type, row) {
				if (type !== 'display') return '';
				var html = '<div class="app-datatable__actions">';
				rowActions.forEach(function (action, i) {
					if (action.visible && !action.visible(row)) return;
					var url = action.url ? resolveTemplate(action.url, row) : '#';
					if (action.type === 'link') {
						html += '<a href="' + escapeHtml(url) + '"'
							+ ' class="ds-button"'
							+ ' data-variant="' + (action.variant || 'secondary') + '"'
							+ ' data-size="sm">'
							+ escapeHtml(action.label) + '</a>';
					} else if (action.type === 'delete') {
						html += '<button type="button"'
							+ ' class="ds-button js-appdt-delete"'
							+ ' data-variant="' + (action.variant || 'tertiary') + '"'
							+ ' data-size="sm" data-color="danger"'
							+ ' data-action-idx="' + i + '"'
							+ ' data-delete-url="' + escapeHtml(url) + '">'
							+ escapeHtml(action.label) + '</button>';
					} else if (action.type === 'custom') {
						html += '<button type="button"'
							+ ' class="ds-button js-appdt-custom"'
							+ ' data-variant="' + (action.variant || 'secondary') + '"'
							+ ' data-size="sm"'
							+ ' data-action-idx="' + i + '">'
							+ escapeHtml(action.label) + '</button>';
					}
				});
				html += '</div>';
				return html;
			}
		};
	}

	// ------------------------------------------------------------------
	// Toolbar buttons builder
	// ------------------------------------------------------------------
	var DS_BUTTON_ATTR = {'data-variant': 'secondary', 'data-size': 'sm'};

	function buildButtonDefs(config) {
		var buttons = [];

		// New item button (like datatable2.twig new_item)
		if (config.newItem) {
			var ni = config.newItem;
			buttons.push({
				text: ni.label || ni.text || 'New',
				className: ni.className || '',
				attr: ni.attr || DS_BUTTON_ATTR,
				action: ni.action || function () {
					if (ni.url) window.open(ni.url, ni.target || '_self');
				}
			});
		}

		// CSV export
		if (config.csvExport !== false) {
			var csvOpts = typeof config.csvExport === 'object' ? config.csvExport : {};
			buttons.push({
				extend: 'csvHtml5',
				titleAttr: csvOpts.title || 'CSV',
				attr: DS_BUTTON_ATTR,
				fieldSeparator: csvOpts.separator || ';',
				bom: csvOpts.bom !== false
			});
		}

		// Download (server-side export)
		if (config.downloadUrl) {
			var dlLang = config.downloadLang || {};
			buttons.push({
				text: dlLang.label || 'Download',
				titleAttr: dlLang.title || 'Download data',
				attr: DS_BUTTON_ATTR,
				action: function (e, dt) {
					var params = {};
					params.length = -1;
					var search = dt.search();
					var iframe = document.createElement('iframe');
					iframe.style.height = '0';
					iframe.style.width = '0';
					iframe.src = config.downloadUrl + (config.downloadUrl.indexOf('?') > -1 ? '&' : '?') +
						'export=1&query=' + encodeURIComponent(search || '');
					document.body.appendChild(iframe);
				}
			});
		}

		// Column visibility toggle
		if (config.columnVisibility) {
			buttons.push({
				extend: 'colvis',
				text: config.columnVisibilityLabel || 'Columns',
				attr: DS_BUTTON_ATTR
			});
		}

		// Custom toolbar buttons
		if (config.buttons && config.buttons.length) {
			config.buttons.forEach(function (btn) {
				if (btn.extend) {
					if (!btn.attr) btn.attr = DS_BUTTON_ATTR;
					buttons.push(btn);
				} else {
					buttons.push({
						text: btn.label || btn.text || '',
						className: btn.className || '',
						attr: btn.attr || DS_BUTTON_ATTR,
						enabled: btn.enabled !== false,
						action: btn.action || function () {
							if (btn.url) window.open(btn.url, btn.target || '_self');
						}
					});
				}
			});
		}

		return buttons.length > 0 ? buttons : null;
	}

	// ------------------------------------------------------------------
	// Column search
	// ------------------------------------------------------------------
	function setupColumnSearch(table, tableEl, config) {
		var active = false;
		var hiddenCols = [];
		var searchLang = (config.lang && config.lang.search) || 'Search';

		function enable() {
			if (active) {
				disable();
				return;
			}
			active = true;

			var headerCells = tableEl.querySelectorAll('thead th');
			headerCells.forEach(function (th, colIdx) {
				var col = table.column(colIdx);
				var settings = table.settings()[0];
				if (settings.aoColumns[colIdx] && settings.aoColumns[colIdx].bSearchable) {
					var title = th.textContent;
					var currentSearch = col.search() || '';
					th.innerHTML = '';
					var input = document.createElement('input');
					input.type = 'text';
					input.className = 'ds-input app-datatable__col-search';
					input.placeholder = searchLang + ' ' + title;
					input.value = currentSearch;
					input.title = title;
					th.appendChild(input);

					var lastCallback = 0;
					input.addEventListener('keyup', function () {
						if (lastCallback >= (Date.now() - 200)) return;
						lastCallback = Date.now();
						col.search(this.value).draw();
					});
					input.addEventListener('click', function (e) {
						e.stopPropagation();
					});
				} else {
					col.visible(false);
					hiddenCols.push(colIdx);
				}
			});

			if (table.responsive) {
				table.responsive.recalc();
			}
		}

		function disable() {
			hiddenCols.forEach(function (idx) {
				table.column(idx).visible(true);
			});
			hiddenCols = [];

			var headerCells = tableEl.querySelectorAll('thead th');
			headerCells.forEach(function (th, colIdx) {
				var input = th.querySelector('.app-datatable__col-search');
				if (input) {
					th.textContent = input.placeholder.replace(searchLang + ' ', '');
				}
			});

			active = false;
			if (table.responsive) {
				table.responsive.recalc();
			}
		}

		return {enable: enable, disable: disable, isActive: function () { return active; }};
	}

	// ------------------------------------------------------------------
	// State management
	// ------------------------------------------------------------------
	function buildStateKey(config) {
		if (config.stateKey) return config.stateKey;
		// Auto-generate from URL like datatable2.twig does
		try {
			var url = new URL(window.location.href);
			var menuaction = url.searchParams.get('menuaction');
			if (menuaction) return 'appdt_' + menuaction.replace(/\./g, '_');
		} catch (e) { /* ignore */ }
		return null;
	}

	// ------------------------------------------------------------------
	// Override the default pagingButton renderer to add DS data attributes
	// directly when buttons are created (avoids timing issues with post-processing).
	var _origPagingButton = DataTable.ext.renderer.pagingButton._;
	DataTable.ext.renderer.pagingButton._ = function (settings, buttonType, content, active, disabled) {
		var result = _origPagingButton.call(this, settings, buttonType, content, active, disabled);
		if (buttonType !== 'ellipsis') {
			var el = result.clicker;
			// jQuery or DOM element
			if (el.jquery) el = el[0];
			if (el) {
				el.setAttribute('data-variant', 'tertiary');
				el.setAttribute('data-size', 'sm');
			}
		}
		return result;
	};

	// ------------------------------------------------------------------
	// DS spinner SVG for processing indicator
	// ------------------------------------------------------------------
	var DS_SPINNER_HTML = '<svg class="ds-spinner" role="img" viewBox="0 0 50 50" data-size="md" aria-label="Loading...">'
		+ '<circle class="ds-spinner__background" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle>'
		+ '<circle class="ds-spinner__circle" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle>'
		+ '</svg>';

	// ------------------------------------------------------------------
	// Main init
	// ------------------------------------------------------------------
	function init(config) {
		var containerId = config.id || 'app-datatable';
		var container = document.getElementById(containerId);
		if (!container) {
			console.error('AppDatatable: container #' + containerId + ' not found');
			return null;
		}

		container.classList.add('app-datatable');
		container.innerHTML = '';

		// Alerts
		var alerts = createAlertSystem(container);

		// Filters
		var filterSystem = buildFilters(container, config.filters, config);

		// Table element
		var tableEl = document.createElement('table');
		tableEl.className = 'ds-table';
		tableEl.setAttribute('data-zebra', '');
		tableEl.setAttribute('data-hover', '');
		tableEl.setAttribute('data-border', '');
		tableEl.style.width = '100%';

		// Footer
		if (config.footer) {
			var tfoot = document.createElement('tfoot');
			var tfootRow = document.createElement('tr');
			(config.columns || []).forEach(function (col) {
				var th = document.createElement('th');
				th.textContent = col.footerText || '';
				tfootRow.appendChild(th);
			});
			if (config.rowActions && config.rowActions.length) {
				tfootRow.appendChild(document.createElement('th'));
			}
			tfoot.appendChild(tfootRow);
			tableEl.appendChild(tfoot);
		}

		container.appendChild(tableEl);

		// Columns
		var columns = (config.columns || []).map(function (col) {
			var colDef = {
				data: col.data,
				title: col.title || '',
				orderable: col.sortable !== false,
				searchable: col.searchable !== false,
				visible: col.hidden !== true,
				className: col.className || '',
				defaultContent: col.defaultContent || ''
			};
			if (col.render) colDef.render = col.render;
			return colDef;
		});

		// Append actions column
		if (config.rowActions && config.rowActions.length) {
			columns.push(buildActionsColumn(config.rowActions));
		}

		// Collect lookup renderers that need preloading
		var lookupLoads = [];
		columns.forEach(function (col) {
			if (col.render && col.render._load) {
				lookupLoads.push(col.render._load());
			}
		});

		// DataTables language — Norwegian defaults, overridable via config.lang
		var dtLang = Object.assign({
			search: 'Søk:',
			emptyTable: 'Ingen oppføringer funnet',
			info: 'Viser _START_ til _END_ av _TOTAL_ oppføringer',
			infoEmpty: 'Ingen oppføringer å vise',
			infoFiltered: '(filtrert fra _MAX_ totalt)',
			lengthMenu: 'Vis _MENU_ oppføringer',
			zeroRecords: 'Ingen samsvarende oppføringer funnet',
			loadingRecords: DS_SPINNER_HTML,
			processing: DS_SPINNER_HTML,
			paginate: {
				first: 'Første',
				last: 'Siste',
				next: 'Neste',
				previous: 'Forrige'
			}
		}, config.lang || {});

		// Buttons
		var buttonDefs = buildButtonDefs(config);

		// Layout (mirrors datatable2.twig)
		var layout;
		if (buttonDefs && buttonDefs.length > 8) {
			layout = {
				top2Start: 'buttons',
				topStart: null,
				topEnd: 'search',
				bottomStart: ['pageLength', 'info'],
				bottomEnd: 'paging'
			};
		} else if (buttonDefs) {
			layout = {
				topStart: 'buttons',
				topEnd: 'search',
				bottomStart: ['pageLength', 'info'],
				bottomEnd: 'paging'
			};
		} else {
			layout = {
				topEnd: 'search',
				bottomStart: ['pageLength', 'info'],
				bottomEnd: 'paging'
			};
		}
		if (config.layout) {
			layout = config.layout;
		}

		// Build DataTables config
		var dtConfig = {
			columns: columns,
			order: config.order || [[0, 'asc']],
			pageLength: config.pageLength || 25,
			language: dtLang,
			layout: layout,
			autoWidth: config.autoWidth !== false,
			classes: {
				search: {
					container: 'dt-search',
					input: 'dt-input ds-input'
				},
				length: {
					container: 'dt-length',
					select: 'dt-input ds-select'
				},
				paging: {
					button: 'ds-button',
					active: 'current',
					disabled: 'disabled',
					container: 'dt-paging',
					nav: ''
				}
			}
		};

		// Length menu
		if (config.lengthMenu) {
			dtConfig.lengthMenu = config.lengthMenu;
		}

		// Initial search
		if (config.initialSearch) {
			dtConfig.search = {search: config.initialSearch};
		}

		// Responsive
		if (config.responsive === 'details') {
			dtConfig.responsive = {
				details: {
					display: DataTable.Responsive
						? DataTable.Responsive.display.childRowImmediate
						: true,
					type: ''
				}
			};
		} else if (config.responsive !== false) {
			dtConfig.responsive = true;
		}

		// Buttons
		if (buttonDefs) {
			dtConfig.buttons = {
				dom: {
					button: {
						className: 'ds-button'
					}
				},
				buttons: buttonDefs
			};
		}

		// State save
		var resolvedStateKey = buildStateKey(config);
		if (config.stateSave !== false && resolvedStateKey) {
			dtConfig.stateSave = true;
			dtConfig.stateDuration = -1; // sessionStorage

			dtConfig.stateSaveParams = function (settings, data) {
				if (filterSystem) {
					data._appdt_filters = filterSystem.getValues();
				}
			};

			dtConfig.stateLoadParams = function (settings, data) {
				if (filterSystem && data._appdt_filters) {
					var saved = data._appdt_filters;
					filterSystem.inputs.forEach(function (inp) {
						var val = saved[inp.config.name];
						if (val == null) return;
						if (inp.el.type === 'checkbox') {
							inp.el.checked = !!val;
						} else if (inp.el.tagName === 'SELECT') {
							inp.el.value = String(val);
						} else {
							inp.el.value = val;
						}
					});
				}
			};
		}

		// Row selection
		if (config.selectable) {
			dtConfig.select = config.selectable === 'multi' ? {style: 'multi'} : true;
		}

		// Search delay (important for server-side)
		if (config.searchDelay != null) {
			dtConfig.searchDelay = config.searchDelay;
		} else if (config.serverSide) {
			dtConfig.searchDelay = 1200;
		}

		// Ajax
		if (config.ajax) {
			var ajaxConfig = {
				url: config.ajax.url,
				type: config.ajax.method || (config.serverSide ? 'POST' : 'GET')
			};

			if (!config.serverSide) {
				ajaxConfig.dataSrc = config.ajax.dataSrc != null ? config.ajax.dataSrc : '';
			} else {
				ajaxConfig.dataSrc = function (json) {
					if (json && json.sessionExpired) {
						alert('Session expired — please log in');
						return [];
					}
					return json.data || [];
				};

				ajaxConfig.data = function (d) {
					// Append filter values for server-side
					if (filterSystem) {
						var vals = filterSystem.getValues();
						Object.keys(vals).forEach(function (k) {
							d[k] = vals[k];
						});
					}
				};
			}

			dtConfig.ajax = ajaxConfig;
		}

		// Server-side
		if (config.serverSide) {
			dtConfig.serverSide = true;
			dtConfig.processing = true;
			dtConfig.deferRender = true;
		}

		// Pagination
		if (config.paginate === false) {
			dtConfig.paging = false;
		}

		// Column defs
		if (config.columnDefs) {
			dtConfig.columnDefs = config.columnDefs;
		}

		// Row callback (e.g. priority classes)
		if (config.rowCallback) {
			dtConfig.rowCallback = config.rowCallback;
		}

		// Footer callback
		if (config.footerCallback) {
			dtConfig.footerCallback = config.footerCallback;
		}

		// Init complete callback
		if (config.onInitComplete) {
			dtConfig.initComplete = config.onInitComplete;
		}

		var table;
		var columnSearch = null;

		function initDT() {
			table = new DataTable(tableEl, dtConfig);

			// Column search support
			if (config.columnSearch) {
				columnSearch = setupColumnSearch(table, tableEl, config);
				// If columnSearch is a button config, it's toggled via a toolbar button
				// Otherwise auto-enable
				if (config.columnSearch === true) {
					columnSearch.enable();
				}
			}

			// Client-side filter integration
			if (filterSystem && !config.serverSide) {
				DataTable.ext.search.push(function (settings, searchData, index, rowData) {
					if (settings.nTable !== tableEl) return true;
					var vals = filterSystem.getValues();
					var pass = true;
					filterSystem.inputs.forEach(function (inp) {
						if (!pass) return;
						var name = inp.config.name;
						var colIdx = inp.config.column;
						var val = vals[name];
						if (inp.config.type === 'checkbox') {
							if (val && inp.config.match) {
								pass = inp.config.match(rowData);
							}
						} else if (inp.config.type === 'select') {
							var firstOpt = inp.config.options && inp.config.options[0];
							if (val && firstOpt && String(val) !== String(firstOpt.value)) {
								if (colIdx != null && colIdx !== '') {
									pass = String(rowData[colIdx]) === String(val);
								}
							}
						} else {
							if (val && colIdx != null && colIdx !== '') {
								var cellVal = String(searchData[colIdx] || '').toLowerCase();
								pass = cellVal.indexOf(val.toLowerCase()) !== -1;
							}
						}
					});
					return pass;
				});

				filterSystem.element.addEventListener('filter-change', function () {
					table.draw();
				});

				// Trigger initial draw if we restored filters from session
				var restored = filterSystem.getValues();
				var hasActive = Object.keys(restored).some(function (k) { return !!restored[k]; });
				if (hasActive) {
					setTimeout(function () { table.draw(); }, 0);
				}
			}

			// Server-side filter integration
			if (filterSystem && config.serverSide) {
				filterSystem.element.addEventListener('filter-change', function () {
					table.ajax.reload();
				});
			}

			// Delete handler via delegation
			if (config.rowActions) {
				tableEl.addEventListener('click', function (e) {
					var deleteBtn = e.target.closest('.js-appdt-delete');
					if (deleteBtn) {
						var idx = parseInt(deleteBtn.dataset.actionIdx, 10);
						var action = config.rowActions[idx];
						if (!action) return;

						if (action.confirm && !confirm(action.confirm)) return;

						deleteBtn.disabled = true;
						var url = deleteBtn.dataset.deleteUrl;

						fetch(url, {method: 'DELETE', credentials: 'same-origin'})
							.then(function (r) {
								if (!r.ok) {
									return r.json().then(function (d) {
										throw new Error(d.error || 'Delete failed');
									});
								}
								var row = table.row(deleteBtn.closest('tr'));
								var rowData = row.data();
								row.remove().draw(false);
								if (action.successMessage) {
									alerts.show('success', action.successMessage);
								}
								if (config.onDelete) {
									config.onDelete(rowData);
								}
							})
							.catch(function (err) {
								alerts.show('danger', err.message);
								deleteBtn.disabled = false;
							});
						return;
					}

					var customBtn = e.target.closest('.js-appdt-custom');
					if (customBtn) {
						var cIdx = parseInt(customBtn.dataset.actionIdx, 10);
						var cAction = config.rowActions[cIdx];
						if (!cAction || !cAction.handler) return;
						var cRow = table.row(customBtn.closest('tr'));
						cAction.handler(cRow.data(), handle);
					}
				});
			}

			// Row click handler
			if (config.onRowClick) {
				tableEl.addEventListener('click', function (e) {
					if (e.target.closest('.app-datatable__actions') || e.target.closest('a') || e.target.closest('button')) return;
					var tr = e.target.closest('tr');
					if (!tr || tr.parentElement.tagName === 'THEAD' || tr.classList.contains('child')) return;
					var row = table.row(tr);
					if (row.data()) {
						config.onRowClick(row.data(), e);
					}
				});
			}

			// Double-click handler
			if (config.onDblClick) {
				tableEl.addEventListener('dblclick', function (e) {
					var tr = e.target.closest('tr');
					if (!tr || tr.parentElement.tagName === 'THEAD') return;
					var row = table.row(tr);
					if (row.data()) {
						config.onDblClick(row.data(), e);
					}
				});
			}

			// Row selection toggle (like datatable2.twig)
			if (config.selectable) {
				tableEl.querySelector('tbody').addEventListener('click', function (e) {
					var tr = e.target.closest('tr');
					if (!tr || tr.classList.contains('child')) return;
					tr.classList.toggle('selected');

					// Sync checkboxes
					var cb = tr.querySelector('input[type="checkbox"]');
					if (cb) {
						cb.checked = tr.classList.contains('selected');
					}
				});
			}

			// Expose on handle
			handle.table = table;
		}

		// Wait for any lookup preloads, then init
		var handle = {
			table: null,
			reload: function () {
				if (table) table.ajax.reload(null, false);
			},
			showAlert: function (type, msg) {
				alerts.show(type, msg);
			},
			message: function (msg) {
				alerts.message(msg);
			},
			destroy: function () {
				if (table) table.destroy();
				container.innerHTML = '';
			},
			search: function (query) {
				if (table) table.search(query).draw();
			},
			getSelected: function () {
				if (!table) return [];
				var rows = table.rows('.selected');
				return rows.data().toArray();
			},
			toggleColumnSearch: function () {
				if (columnSearch) columnSearch.isActive() ? columnSearch.disable() : columnSearch.enable();
			},
			resetFilters: function () {
				if (filterSystem) {
					filterSystem.reset();
					filterSystem.element.dispatchEvent(new Event('filter-change'));
				}
				if (table) {
					table.search('').draw();
				}
				if (columnSearch && columnSearch.isActive()) {
					columnSearch.disable();
				}
			}
		};

		if (lookupLoads.length) {
			Promise.all(lookupLoads)
				.then(initDT)
				.catch(function () { initDT(); });
		} else {
			initDT();
		}

		return handle;
	}

	// ------------------------------------------------------------------
	// Public API
	// ------------------------------------------------------------------
	window.AppDatatable = {
		init: init,
		render: render
	};
})();
