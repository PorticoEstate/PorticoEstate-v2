(function () {
	var root = document.querySelector('.todo-matrix');
	if (!root) {
		return;
	}

	var statusApiTemplate = root.dataset.statusApiTemplate || '';
	if (!statusApiTemplate || statusApiTemplate.indexOf('__TODO_ID__') === -1) {
		return;
	}

	var langCompleted = root.dataset.langCompleted || 'Completed';
	var langClickToEdit = root.dataset.langClickToEdit || 'Click to edit';
	var langEnterCompleted = root.dataset.langEnterCompleted || 'Enter completed percentage (0-100)';
	var langInvalidStatus = root.dataset.langInvalidStatus || 'Status must be a number between 0 and 100';
	var langSaving = root.dataset.langSaving || 'Saving...';
	var langUpdateFailed = root.dataset.langUpdateFailed || 'Failed to update status';
	var csrfNameKey = root.dataset.csrfNameKey || '';
	var csrfValueKey = root.dataset.csrfValueKey || '';
	var csrfName = root.dataset.csrfName || '';
	var csrfValue = root.dataset.csrfValue || '';

	function appendHiddenCsrfInput(form, name, value) {
		if (!name || !value) {
			return;
		}

		var input = form.querySelector('input[name="' + name + '"]');
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			form.appendChild(input);
		}
		input.value = value;
	}

	var filterForm = root.querySelector('.phpgw-matrixview__filters');
	if (filterForm) {
		appendHiddenCsrfInput(filterForm, csrfNameKey, csrfName);
		appendHiddenCsrfInput(filterForm, csrfValueKey, csrfValue);

		filterForm.addEventListener('change', function (event) {
			var target = event.target;
			if (!target || !target.matches('.phpgw-matrixview__select')) {
				return;
			}

			if (typeof filterForm.requestSubmit === 'function') {
				filterForm.requestSubmit();
				return;
			}

			filterForm.submit();
		});
	}

	function clampStatus(value) {
		var normalized = String(value == null ? '' : value).trim();
		if (!/^\d+$/.test(normalized)) {
			return null;
		}

		var parsed = Number(normalized);
		if (!Number.isInteger(parsed)) {
			return null;
		}
		if (parsed < 0 || parsed > 100) {
			return null;
		}
		return parsed;
	}

	function setStatusVisual(button, status) {
		button.dataset.status = String(status);
		button.title = langCompleted + ': ' + status + '% - ' + langClickToEdit;

		var valueEl = button.querySelector('.todo-matrix__status-value');
		if (valueEl) {
			valueEl.textContent = status + '%';
		}

		var fillEl = button.querySelector('.todo-matrix__status-fill');
		if (fillEl) {
			fillEl.style.width = status + '%';
		}
	}

	function buildStatusUrl(todoId) {
		return statusApiTemplate.replace('__TODO_ID__', encodeURIComponent(String(todoId)));
	}

	function updateStatus(button, todoId, status) {
		var headers = {
			'Content-Type': 'application/json',
			'Accept': 'application/json'
		};
		if (csrfNameKey && csrfValueKey && csrfName && csrfValue) {
			headers[csrfNameKey] = csrfName;
			headers[csrfValueKey] = csrfValue;
			headers['X-CSRF-NAME'] = csrfName;
			headers['X-CSRF-VALUE'] = csrfValue;
		}

		var payload = { status: status };
		if (csrfNameKey && csrfValueKey && csrfName && csrfValue) {
			payload[csrfNameKey] = csrfName;
			payload[csrfValueKey] = csrfValue;
		}

		return fetch(buildStatusUrl(todoId), {
			method: 'PATCH',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify(payload)
		}).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (data) {
				if (!response.ok) {
					throw new Error(data.error || ('HTTP ' + response.status));
				}
				return data;
			});
		});
	}

	root.addEventListener('click', function (event) {
		var button = event.target.closest('.todo-matrix__status[data-todo-id]');
		if (!button) {
			return;
		}

		event.preventDefault();
		if (button.dataset.busy === '1') {
			return;
		}

		var todoId = parseInt(button.dataset.todoId || '0', 10);
		if (!todoId) {
			return;
		}

		var currentStatus = parseInt(button.dataset.status || '0', 10) || 0;
		var entered = window.prompt(langEnterCompleted, String(currentStatus));
		if (entered === null) {
			return;
		}

		var nextStatus = clampStatus(entered);
		if (nextStatus === null) {
			window.alert(langInvalidStatus);
			return;
		}
		if (nextStatus === currentStatus) {
			return;
		}

		button.dataset.busy = '1';
		button.disabled = true;
		var previousTitle = button.title;
		button.title = langSaving;

		updateStatus(button, todoId, nextStatus)
			.then(function () {
				setStatusVisual(button, nextStatus);
			})
			.catch(function (error) {
				window.alert((error && error.message) ? error.message : langUpdateFailed);
				button.title = previousTitle;
			})
			.finally(function () {
				button.disabled = false;
				button.dataset.busy = '0';
			});
	});
})();
