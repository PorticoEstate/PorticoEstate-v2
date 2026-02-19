(function () {
	'use strict';

	var CFG = window.__registryList;
	var L = CFG.lang;

	fetch(CFG.schemaUrl, {credentials: 'same-origin'})
		.then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		})
		.then(function (schema) {
			if (!schema.success) throw new Error('Schema load failed');
			initTable(schema.data);
		})
		.catch(function (err) {
			var el = document.getElementById('app-datatable');
			if (el) el.textContent = 'Failed to load schema: ' + err.message;
		});

	function initTable(schema) {
		var columns = [];
		var filters = [];
		var lookupPromises = [];

		// ID column
		var idField = schema.id_field || {};
		columns.push({
			data: 'id',
			title: 'ID',
			className: 'app-datatable__col-id'
		});

		// Build columns from schema fields
		(schema.fields || []).forEach(function (field, idx) {
			var col = {
				data: field.name,
				title: field.descr || field.name
			};

			if (field.type === 'checkbox') {
				col.render = function (data, type) {
					if (type !== 'display') return data;
					return data == 1 || data === true ? L.yes : L.no;
				};
				// Add as filter
				if (field.filter) {
					filters.push({
						name: field.name,
						label: field.descr || field.name,
						type: 'select',
						column: idx + 1,
						options: [
							{value: '', label: L.all},
							{value: '1', label: L.yes},
							{value: '0', label: L.no}
						]
					});
				}
			} else if (field.type === 'select' && field.lookup_url) {
				col.render = buildLookupRenderer(field.lookup_url, lookupPromises);
				// Add as filter
				if (field.filter) {
					filters.push(buildSelectFilter(field, idx + 1));
				}
			} else if (field.type === 'html') {
				col.render = AppDatatable.render.html();
			}

			columns.push(col);
		});

		// Row actions
		var rowActions = [];
		if (CFG.permissions.write) {
			rowActions.push({
				type: 'link',
				url: CFG.editBaseUrl + '{id}',
				label: L.edit,
				variant: 'secondary'
			});
			rowActions.push({
				type: 'link',
				url: CFG.editBaseUrl + '{id}',
				label: L.edit + ' \u2197',
				variant: 'secondary',
				target: '_blank'
			});
		}
		if (CFG.permissions.delete) {
			rowActions.push({
				type: 'delete',
				url: CFG.dataUrl + '/{id}',
				label: L.delete,
				variant: 'tertiary',
				confirm: L.confirmDelete,
				successMessage: L.deleted
			});
		}

		// Wait for any select filter option fetches
		Promise.all(lookupPromises).then(function () {
			AppDatatable.init({
				id: 'app-datatable',
				newItem: CFG.permissions.create
					? {label: L.add + ' ' + CFG.name, url: CFG.addUrl}
					: undefined,
				ajax: {url: CFG.dataUrl, dataSrc: 'data'},
				columns: columns,
				filters: filters.length ? filters : undefined,
				rowActions: rowActions.length ? rowActions : undefined,
				columnVisibility: true,
				columnVisibilityLabel: L.columns,
				downloadUrl: CFG.downloadUrl,
				downloadLang: {label: L.download},
				order: [[0, 'asc']],
				pageLength: 25,
				lang: {
					emptyTable: L.emptyTable
				}
			});
		});
	}

	function buildLookupRenderer(lookupUrl, promises) {
		var map = null;
		var p = fetch(lookupUrl, {credentials: 'same-origin'})
			.then(function (r) { return r.json(); })
			.then(function (json) {
				map = {};
				var items = json.data || json;
				items.forEach(function (item) {
					map[item.id] = item.name;
				});
			})
			.catch(function () { map = {}; });
		promises.push(p);

		return function (data, type) {
			if (type !== 'display') return data != null ? data : '';
			if (!map) return data != null ? data : '';
			return map[data] || data || '';
		};
	}

	function buildSelectFilter(field, colIdx) {
		var filter = {
			name: field.name,
			label: field.descr || field.name,
			type: 'select',
			column: colIdx,
			options: [{value: '', label: L.all}]
		};

		if (field.lookup_url) {
			// Fetch options synchronously before table init
			var xhr = new XMLHttpRequest();
			xhr.open('GET', field.lookup_url, false);
			xhr.withCredentials = true;
			try {
				xhr.send();
				if (xhr.status === 200) {
					var json = JSON.parse(xhr.responseText);
					var items = json.data || json;
					items.forEach(function (item) {
						filter.options.push({value: String(item.id), label: item.name});
					});
				}
			} catch (e) { /* ignore */ }
		}

		return filter;
	}
})();
