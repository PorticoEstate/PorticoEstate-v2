(function () {
	'use strict';

	var CFG = window.__registryEdit;
	var LANG = CFG.lang;

	// DOM refs
	var form = document.getElementById('registry-form');
	var formFields = document.getElementById('form-fields');
	var alertSuccess = document.getElementById('alert-success');
	var alertError = document.getElementById('alert-error');
	var btnSave = document.getElementById('btn-save');

	var alertTimer = null;
	var schema = null;

	// -- Alerts --

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

	function setSaving(isSaving) {
		btnSave.disabled = isSaving;
		btnSave.textContent = isSaving ? LANG.saving : LANG.save;
	}

	// -- Schema loading and form building --

	function loadSchema() {
		return fetch(CFG.schemaUrl, {credentials: 'same-origin'})
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (json) {
				if (!json.success) throw new Error('Schema load failed');
				schema = json.data;
				return schema;
			});
	}

	function loadItem() {
		if (CFG.isNew || !CFG.itemId) return Promise.resolve(null);
		return fetch(CFG.dataUrl + '/' + CFG.itemId, {credentials: 'same-origin'})
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (json) {
				return json.data || null;
			});
	}

	function buildForm(schema, itemData) {
		formFields.innerHTML = '';
		var idField = schema.id_field || {};

		// ID field
		if (idField.type === 'auto') {
			if (!CFG.isNew && itemData) {
				addField({
					name: 'id', descr: 'ID', type: 'varchar',
					_readonly: true
				}, itemData.id);
			}
			// Hidden on add for auto type
		} else {
			// int or varchar ID — editable on add, read-only on edit
			addField({
				name: 'id', descr: 'ID', type: idField.type || 'int',
				required: true,
				_readonly: !CFG.isNew
			}, itemData ? itemData.id : '');
		}

		// Regular fields
		var selectPromises = [];
		(schema.fields || []).forEach(function (field) {
			var value = itemData ? itemData[field.name] : null;

			if (field.type === 'select' && field.lookup_url) {
				var selectEl = addField(field, value);
				if (selectEl) {
					selectPromises.push(loadSelectOptions(selectEl, field.lookup_url, value));
				}
			} else {
				addField(field, value);
			}
		});

		return Promise.all(selectPromises);
	}

	function addField(field, value) {
		var group = document.createElement('div');
		group.className = 'form-group';

		var label = document.createElement('label');
		label.className = 'app-label';
		label.textContent = field.descr || field.name;
		label.setAttribute('for', 'field-' + field.name);
		group.appendChild(label);

		var input;

		switch (field.type) {
			case 'text':
				input = document.createElement('textarea');
				input.className = 'app-input';
				input.rows = 4;
				input.id = 'field-' + field.name;
				input.name = field.name;
				input.value = value || '';
				break;

			case 'html':
				input = document.createElement('textarea');
				input.className = 'app-input summernote-target';
				input.rows = 8;
				input.id = 'field-' + field.name;
				input.name = field.name;
				input.value = value || '';
				break;

			case 'checkbox':
				input = document.createElement('input');
				input.type = 'checkbox';
				input.className = 'app-checkbox';
				input.id = 'field-' + field.name;
				input.name = field.name;
				if (value != null) {
					input.checked = !!value && value != 0;
				} else if (CFG.isNew && field.default == 1) {
					input.checked = true;
				}
				break;

			case 'select':
				input = document.createElement('select');
				input.className = 'app-select';
				input.id = 'field-' + field.name;
				input.name = field.name;
				// Options loaded separately
				var placeholder = document.createElement('option');
				placeholder.value = '';
				placeholder.textContent = '-- ' + (field.descr || field.name) + ' --';
				input.appendChild(placeholder);
				break;

			case 'int':
				input = document.createElement('input');
				input.type = 'number';
				input.className = 'app-input';
				input.id = 'field-' + field.name;
				input.name = field.name;
				input.value = value != null ? value : '';
				break;

			case 'date':
				input = document.createElement('input');
				input.type = 'date';
				input.className = 'app-input';
				input.id = 'field-' + field.name;
				input.name = field.name;
				input.value = value || '';
				break;

			default: // varchar and others
				input = document.createElement('input');
				input.type = 'text';
				input.className = 'app-input';
				input.id = 'field-' + field.name;
				input.name = field.name;
				input.value = value != null ? value : '';
				if (field.maxlength) input.maxLength = field.maxlength;
				break;
		}

		if (field._readonly) {
			input.readOnly = true;
			input.disabled = true;
			input.classList.add('app-input--readonly');
		}

		if (field.required) {
			input.required = true;
		}

		group.appendChild(input);
		formFields.appendChild(group);

		return input;
	}

	function loadSelectOptions(selectEl, url, selectedValue) {
		return fetch(url, {credentials: 'same-origin'})
			.then(function (r) { return r.json(); })
			.then(function (json) {
				var items = json.data || json;
				items.forEach(function (item) {
					var opt = document.createElement('option');
					opt.value = item.id;
					opt.textContent = item.name;
					if (selectedValue != null && String(item.id) === String(selectedValue)) {
						opt.selected = true;
					}
					selectEl.appendChild(opt);
				});
			})
			.catch(function () { /* ignore */ });
	}

	// -- Summernote WYSIWYG --

	function initSummernote() {
		if (!window.jQuery || !jQuery.fn.summernote) return;
		var targets = formFields.querySelectorAll('.summernote-target');
		for (var i = 0; i < targets.length; i++) {
			jQuery(targets[i]).summernote({
				lang: 'nb-NO',
				height: 200,
				toolbar: [
					['style', ['bold', 'italic', 'underline', 'strikethrough']],
					['para', ['ul', 'ol', 'paragraph']],
					['insert', ['link', 'hr']],
					['misc', ['undo', 'redo']],
					['view', ['codeview']]
				]
			});
		}
	}

	// -- Form submission --

	function collectFormData() {
		var data = {};
		var fields = schema.fields || [];

		// ID field (only for non-auto types on add)
		var idField = schema.id_field || {};
		var idInput = document.getElementById('field-id');
		if (idInput && !idInput.disabled) {
			data.id = idField.type === 'int' ? parseInt(idInput.value, 10) : idInput.value;
		}

		fields.forEach(function (field) {
			var el = document.getElementById('field-' + field.name);
			if (!el || el.disabled) return;

			if (field.type === 'checkbox') {
				data[field.name] = el.checked ? 1 : 0;
			} else if (field.type === 'int') {
				data[field.name] = el.value !== '' ? parseInt(el.value, 10) : null;
			} else if (field.type === 'select') {
				data[field.name] = el.value !== '' ? el.value : null;
			} else if (field.type === 'html' && window.jQuery && jQuery(el).hasClass('summernote-target')) {
				data[field.name] = jQuery(el).summernote('code');
			} else {
				data[field.name] = el.value;
			}
		});

		return data;
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		hideAlerts();
		setSaving(true);

		var data = collectFormData();
		var method = CFG.isNew ? 'POST' : 'PUT';
		var url = CFG.isNew ? CFG.dataUrl : CFG.dataUrl + '/' + CFG.itemId;

		fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify(data)
		})
			.then(function (r) {
				if (!r.ok) {
					return r.json().then(function (d) {
						var msg = d.message || LANG.saveFailed;
						if (d.errors) {
							var errMsgs = [];
							if (Array.isArray(d.errors)) {
								errMsgs = d.errors;
							} else {
								Object.keys(d.errors).forEach(function (k) {
									errMsgs.push(k + ': ' + d.errors[k]);
								});
							}
							if (errMsgs.length) msg += ' — ' + errMsgs.join(', ');
						}
						throw new Error(msg);
					});
				}
				return r.json();
			})
			.then(function (result) {
				showAlert(alertSuccess, LANG.saved);
				if (CFG.isNew && result.data && result.data.id) {
					// Switch from add to edit mode with the new ID
					var editUrl = CFG.listUrl.replace(/\/$/, '') + '/' + result.data.id;
					window.location.href = editUrl;
				}
			})
			.catch(function (err) {
				showAlert(alertError, err.message);
			})
			.finally(function () {
				setSaving(false);
			});
	});

	// -- Boot --

	loadSchema()
		.then(function (s) {
			return loadItem().then(function (item) {
				return buildForm(s, item);
			});
		})
		.then(function () {
			initSummernote();
		})
		.catch(function (err) {
			showAlert(alertError, 'Failed to load: ' + err.message);
		});
})();
