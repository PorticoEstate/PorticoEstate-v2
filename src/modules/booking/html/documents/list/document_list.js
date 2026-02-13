(function () {
	'use strict';

	var CONFIG = window.__documentList;
	var LANG = CONFIG.lang;
	var CAN_WRITE = CONFIG.canWrite;
	var CAN_DELETE = CONFIG.canDelete;
	var categoryMap = {};
	var table = null;
	var alertTimer = null;

	var alertSuccess = document.getElementById('alert-success');
	var alertError = document.getElementById('alert-error');

	function showAlert(el, msg) {
		if (alertTimer) clearTimeout(alertTimer);
		hideAlerts();
		el.textContent = msg;
		el.classList.remove('is-hidden', 'is-fading');
		alertTimer = setTimeout(function () {
			el.classList.add('is-fading');
			setTimeout(function () { el.classList.add('is-hidden'); }, 300);
		}, 4000);
	}

	function hideAlerts() {
		alertSuccess.classList.add('is-hidden');
		alertSuccess.classList.remove('is-fading');
		alertError.classList.add('is-hidden');
		alertError.classList.remove('is-fading');
	}

	function translateCategory(value) {
		if (!value) return '';
		return categoryMap[value] || value;
	}

	function escapeHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	function loadCategories() {
		return fetch('/booking/buildings/documents/categories', { credentials: 'same-origin' })
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (categories) {
				categories.forEach(function (cat) {
					categoryMap[cat.value] = cat.label;
				});
			});
	}

	function initTable() {
		table = new DataTable('#document-table', {
			ajax: {
				url: '/booking/buildings/documents',
				dataSrc: ''
			},
			columns: [
				{
					data: 'name',
					title: LANG.document,
					render: function (data, type, row) {
						if (type !== 'display') return data || '';
						var url = '/booking/buildings/documents/' + row.id + '/download';
						return '<a href="' + url + '" target="_blank">' + escapeHtml(data) + '</a>';
					}
				},
				{
					data: 'owner_name',
					title: LANG.building
				},
				{
					data: 'description',
					title: LANG.description
				},
				{
					data: 'category',
					title: LANG.category,
					render: function (data, type) {
						if (type !== 'display') return data || '';
						return escapeHtml(translateCategory(data));
					}
				},
				{
					data: null,
					title: '',
					orderable: false,
					searchable: false,
					visible: CAN_WRITE || CAN_DELETE,
					render: function (data, type, row) {
						if (type !== 'display') return '';
						var html = '<div class="document-list__actions">';
						if (CAN_WRITE) {
							var editUrl = '/booking/view/buildings/documents/' + row.id + '/edit';
							html += '<a href="' + editUrl + '" class="ds-button" data-variant="secondary" data-size="sm">' + LANG.edit + '</a>';
						}
						if (CAN_DELETE) {
							html += '<button type="button" class="ds-button js-delete" data-variant="tertiary" data-size="sm" data-color="danger" ' +
								'data-id="' + row.id + '" data-owner="' + row.owner_id + '">' +
								LANG.delete + '</button>';
						}
						html += '</div>';
						return html;
					}
				}
			],
			order: [[0, 'asc']],
			pageLength: 25,
			language: {
				search: LANG.search + ':',
				emptyTable: LANG.emptyTable,
				info: LANG.info,
				infoEmpty: LANG.infoEmpty,
				lengthMenu: LANG.lengthMenu,
				paginate: {
					first: LANG.first,
					last: LANG.last,
					next: LANG.next,
					previous: LANG.previous
				}
			}
		});
	}

	// Delete handler via event delegation
	document.getElementById('document-table').addEventListener('click', function (e) {
		var btn = e.target.closest('.js-delete');
		if (!btn) return;

		var docId = btn.getAttribute('data-id');
		var ownerId = btn.getAttribute('data-owner');
		if (!confirm(LANG.confirmDelete)) return;

		btn.disabled = true;

		fetch('/booking/buildings/' + ownerId + '/documents/' + docId, {
			method: 'DELETE',
			credentials: 'same-origin'
		})
			.then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'Delete failed'); });
				// Remove row from DataTable
				var row = table.row(btn.closest('tr'));
				row.remove().draw(false);
				showAlert(alertSuccess, LANG.deleted);
			})
			.catch(function (err) {
				showAlert(alertError, err.message);
				btn.disabled = false;
			});
	});

	// Boot
	loadCategories()
		.then(initTable)
		.catch(function () { initTable(); });
})();
