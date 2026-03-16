/**
 * Reusable Hospitality Order List
 *
 * Renders a table of hospitality orders with configurable columns.
 *
 * Usage:
 *   var list = new HospitalityOrderList(containerEl, {
 *       orders: [...],
 *       lang: function(key) { return translated; },
 *       columns: {
 *           application: true,   // show application column
 *           hospitality: false,  // show hospitality name column
 *       },
 *       emptyText: 'No orders',
 *   });
 *
 *   list.update(newOrders);  // re-render with new data
 */
class HospitalityOrderList {
	constructor(container, options) {
		this.container = container;
		this.lang = options.lang || function (k) { return k; };
		this.columns = options.columns || {};
		this.emptyText = options.emptyText || 'No orders';
		this.orders = options.orders || [];

		// Attach click handler once on the container
		this.container.addEventListener('click', function (e) {
			var row = e.target.closest('[data-order-link]');
			if (!row) return;
			window.location.href = '/booking/view/hospitality-orders/' + row.dataset.orderLink;
		});

		this._render();
	}

	update(orders) {
		this.orders = orders || [];
		this._render();
	}

	_esc(str) {
		if (str == null) return '';
		var div = document.createElement('div');
		div.textContent = String(str);
		return div.innerHTML;
	}

	_fmtDate(str) {
		if (!str) return '';
		var d = new Date(str);
		if (isNaN(d)) return this._esc(str);
		return d.toLocaleDateString('nb-NO', {
			day: '2-digit', month: '2-digit', year: 'numeric',
			hour: '2-digit', minute: '2-digit'
		});
	}

	_statusColor(status) {
		var map = {
			pending: 'warning',
			confirmed: 'info',
			delivered: 'success',
			cancelled: 'danger'
		};
		return map[(status || '').toLowerCase()] || 'neutral';
	}

	_render() {
		var self = this;
		var orders = this.orders;
		var lang = this.lang;
		var showApp = !!this.columns.application;
		var showHosp = !!this.columns.hospitality;

		if (!orders || orders.length === 0) {
			this.container.innerHTML = '<p class="app-show__empty">' + self._esc(this.emptyText) + '</p>';
			return;
		}

		var html = '<table class="ds-table" data-border>' +
			'<thead><tr>' +
			'<th>ID</th>';

		if (showApp) html += '<th>' + self._esc(lang('application')) + '</th>';
		if (showHosp) html += '<th>' + self._esc(lang('hospitality')) + '</th>';

		html += '<th>' + self._esc(lang('status')) + '</th>' +
			'<th>' + self._esc(lang('location')) + '</th>' +
			'<th>' + self._esc(lang('servingTime')) + '</th>' +
			'<th>' + self._esc(lang('amount')) + '</th>' +
			'<th>' + self._esc(lang('created')) + '</th>' +
			'</tr></thead><tbody>';

		orders.forEach(function (order) {
			var statusColor = self._statusColor(order.status);
			var statusTag = '<span class="ds-tag" data-color="' + statusColor + '">' + self._esc(order.status) + '</span>';
			var amount = order.total_amount != null ? Number(order.total_amount).toFixed(2) : '&mdash;';

			html += '<tr class="hospitality-order-list__row" data-order-link="' + self._esc(order.id) + '" style="cursor:pointer">';
			html += '<td>' + self._esc(order.id) + '</td>';

			if (showApp) html += '<td>#' + self._esc(order.application_id) + '</td>';
			if (showHosp) html += '<td>' + self._esc(order.hospitality_name) + '</td>';

			html += '<td>' + statusTag + '</td>';
			html += '<td>' + self._esc(order.location_name) + '</td>';
			html += '<td>' + self._fmtDate(order.serving_time_iso) + '</td>';
			html += '<td>' + amount + '</td>';
			html += '<td>' + self._fmtDate(order.created) + '</td>';
			html += '</tr>';
		});

		html += '</tbody></table>';

		this.container.innerHTML = html;
	}
}
