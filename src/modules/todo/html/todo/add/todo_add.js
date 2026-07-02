(function () {
	var root = document.getElementById('todo-add-app');
	if (!root) {
		return;
	}

	var form = document.getElementById('todo-add-form');
	var saveBtn = document.getElementById('todo-save');
	var errorEl = document.getElementById('todo-add-error');
	var apiUrl = root.dataset.apiUrl;
	var backUrl = root.dataset.backUrl;

	function initSelect2Multi(selector) {
		if (typeof window.jQuery === 'undefined' || typeof window.jQuery.fn.select2 === 'undefined') {
			return;
		}

		var $el = window.jQuery(selector);
		if (!$el.length) {
			return;
		}

		$el.select2({
			width: '100%',
			closeOnSelect: false,
			minimumResultsForSearch: 0,
			placeholder: $el.data('placeholder') || '',
			templateResult: function (data) {
				if (!data.id) {
					return data.text;
				}

				var selected = false;
				if (data.element) {
					selected = data.element.selected;
				}

				var span = document.createElement('span');
				span.className = 'todo-add__select2-option';

				var checkbox = document.createElement('input');
				checkbox.type = 'checkbox';
				checkbox.className = 'todo-add__select2-checkbox';
				checkbox.checked = selected;
				checkbox.tabIndex = -1;

				var label = document.createElement('span');
				label.textContent = data.text;

				span.appendChild(checkbox);
				span.appendChild(label);
				return span;
			}
		});

	}

	function showError(message) {
		errorEl.hidden = false;
		errorEl.textContent = message || root.dataset.langError || 'Error';
	}

	function hideError() {
		errorEl.hidden = true;
		errorEl.textContent = '';
	}

	function splitDate(value) {
		if (!value || value.indexOf('-') === -1) {
			return { year: '', month: '', day: '' };
		}
		var parts = value.split('-');
		if (parts.length !== 3) {
			return { year: '', month: '', day: '' };
		}
		return {
			year: parseInt(parts[0], 10) || '',
			month: parseInt(parts[1], 10) || '',
			day: parseInt(parts[2], 10) || ''
		};
	}

	function getMultiValues(el) {
		return Array.prototype.slice.call(el.selectedOptions || []).map(function (option) {
			return option.value;
		});
	}

	function buildPayload() {
		var sDate = splitDate(form.elements.sdate.value);
		var eDate = splitDate(form.elements.edate.value);

		return {
			title: form.elements.title.value || '',
			descr: form.elements.descr.value || '',
			cat: parseInt(form.elements.cat.value || '0', 10) || 0,
			parent: parseInt(form.elements.parent.value || '0', 10) || 0,
			pri: parseInt(form.elements.pri.value || '2', 10) || 2,
			status: parseInt(form.elements.status.value || '0', 10) || 0,
			access: form.elements.access.checked,
			assigned: getMultiValues(form.elements.assigned),
			assigned_group: getMultiValues(form.elements.assigned_group),
			syear: sDate.year,
			smonth: sDate.month,
			sday: sDate.day,
			eyear: eDate.year,
			emonth: eDate.month,
			eday: eDate.day
		};
	}

	initSelect2Multi('#todo-assigned');
	initSelect2Multi('#todo-assigned-group');

	form.addEventListener('submit', function (event) {
		event.preventDefault();
		hideError();

		var oldText = saveBtn.textContent;
		saveBtn.disabled = true;
		saveBtn.textContent = root.dataset.langSaving || oldText;

		fetch(apiUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json'
			},
			credentials: 'same-origin',
			body: JSON.stringify(buildPayload())
		})
			.then(function (response) {
				return response.json().catch(function () { return {}; }).then(function (data) {
					if (!response.ok) {
						throw new Error(data.error || ('HTTP ' + response.status));
					}
					return data;
				});
			})
			.then(function () {
				window.location.href = backUrl;
			})
			.catch(function (error) {
				showError(error.message || root.dataset.langError);
			})
			.finally(function () {
				saveBtn.disabled = false;
				saveBtn.textContent = oldText;
			});
	});
})();
