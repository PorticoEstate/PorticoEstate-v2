document.addEventListener('DOMContentLoaded', function () {
	const app = document.getElementById('passkey-management-app');
	if (!app) {
		return;
	}

	const compatibilityCheck = document.getElementById('passkey-compatibility-check');
	const notSupportedMessage = document.getElementById('passkey-not-supported');
	const passkeyContent = document.getElementById('passkey-content');
	const listContainer = document.getElementById('passkey-list-container');
	const listLoading = document.getElementById('passkey-list-loading');
	const listError = document.getElementById('passkey-list-error');
	const emptyMessage = document.getElementById('no-passkeys-message');
	const status = document.getElementById('passkey-status');
	const registerForm = document.getElementById('register-passkey-form');
	const registerButton = document.getElementById('register-passkey-button');
	const passkeyNameInput = document.getElementById('passkey-name');

	const messages = {
		name: app.dataset.langName,
		created: app.dataset.langCreated,
		lastUsed: app.dataset.langLastUsed,
		actions: app.dataset.langActions,
		delete: app.dataset.langDelete,
		loadError: app.dataset.langLoadError,
		confirmDelete: app.dataset.langConfirmDelete,
		deleteError: app.dataset.langDeleteError,
		deleteFailed: app.dataset.langDeleteFailed,
		deleteSuccess: app.dataset.langDeleteSuccess,
		registrationError: app.dataset.langRegistrationError,
		registrationFailed: app.dataset.langRegistrationFailed,
		registrationSuccess: app.dataset.langRegistrationSuccess,
		registering: app.dataset.langRegistering,
		nameRequired: app.dataset.langNameRequired,
		never: app.dataset.langNever,
		unknown: app.dataset.langUnknown,
		unnamed: app.dataset.langUnnamed,
		notSupported: app.dataset.langNotSupported,
		sessionExpired: app.dataset.langSessionExpired
	};

	function showStatus(type, message) {
		status.textContent = message;
		status.className = 'alert mt-3';
		status.classList.add(type === 'success' ? 'alert-success' : type === 'error' ? 'alert-danger' : 'alert-info');
		status.hidden = false;
		if (type !== 'error') {
			window.setTimeout(function () { status.hidden = true; }, 10000);
		}
	}

	function addCell(row, value) {
		const cell = document.createElement('td');
		cell.textContent = value;
		row.appendChild(cell);
		return cell;
	}

	function renderPasskeys(passkeys) {
		listContainer.replaceChildren();
		emptyMessage.hidden = passkeys.length > 0;
		if (passkeys.length === 0) {
			return;
		}

		const table = document.createElement('table');
		table.className = 'table table-hover';
		const head = document.createElement('thead');
		const headerRow = document.createElement('tr');
		[messages.name, messages.created, messages.lastUsed, messages.actions].forEach(function (label) {
			const cell = document.createElement('th');
			cell.scope = 'col';
			cell.textContent = label;
			headerRow.appendChild(cell);
		});
		head.appendChild(headerRow);
		table.appendChild(head);

		const body = document.createElement('tbody');
		passkeys.forEach(function (passkey) {
			const row = document.createElement('tr');
			addCell(row, passkey.device_name || messages.unnamed);
			addCell(row, passkey.created || messages.unknown);
			addCell(row, passkey.last_used || messages.never);

			const actionCell = document.createElement('td');
			const deleteButton = document.createElement('button');
			deleteButton.type = 'button';
			deleteButton.className = 'btn btn-sm btn-danger delete-passkey-button';
			deleteButton.title = messages.delete;
			deleteButton.textContent = messages.delete;
			deleteButton.addEventListener('click', function () {
				deletePasskey(passkey.credential_id, deleteButton);
			});
			actionCell.appendChild(deleteButton);
			row.appendChild(actionCell);
			body.appendChild(row);
		});
		table.appendChild(body);
		listContainer.appendChild(table);
	}

	async function readJson(response, fallbackMessage) {
		let payload;
		try {
			payload = await response.json();
		} catch (error) {
			throw new Error(fallbackMessage);
		}
		if (!response.ok) {
			throw new Error(payload.message || fallbackMessage);
		}
		return payload;
	}

	async function loadPasskeys() {
		listLoading.hidden = false;
		listError.hidden = true;
		try {
			const response = await fetch(app.dataset.apiUrl, {
				headers: {Accept: 'application/json'},
				credentials: 'same-origin'
			});
			const payload = await readJson(response, messages.loadError);
			renderPasskeys(payload.data && Array.isArray(payload.data.passkeys) ? payload.data.passkeys : []);
		} catch (error) {
			listError.textContent = error.message || messages.loadError;
			listError.hidden = false;
			listContainer.replaceChildren();
			emptyMessage.hidden = true;
		} finally {
			listLoading.hidden = true;
		}
	}

	async function deletePasskey(credentialId, button) {
		if (!window.confirm(messages.confirmDelete)) {
			return;
		}
		button.disabled = true;
		try {
			const response = await fetch(app.dataset.deleteUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-Token': app.dataset.csrfToken
				},
				body: JSON.stringify({credential_id: credentialId}),
				credentials: 'same-origin'
			});
			const payload = await readJson(response, messages.deleteError);
			if (payload.success !== true) {
				throw new Error(payload.message || messages.deleteFailed);
			}
			showStatus('success', messages.deleteSuccess);
			await loadPasskeys();
		} catch (error) {
			window.alert(error.message || messages.deleteError);
			button.disabled = false;
		}
	}

	function withQueryParams(url, params) {
		const endpoint = new URL(url, window.location.href);
		Object.entries(params).forEach(function ([key, value]) {
			endpoint.searchParams.set(key, value);
		});
		return endpoint.toString();
	}

	registerForm.addEventListener('submit', async function (event) {
		event.preventDefault();
		const deviceName = passkeyNameInput.value.trim();
		if (!deviceName) {
			showStatus('error', messages.nameRequired);
			passkeyNameInput.focus();
			return;
		}

		registerButton.disabled = true;
		showStatus('info', messages.registering);
		try {
			const optionsUrl = withQueryParams(app.dataset.registrationOptionsUrl, {
				csrf_token: app.dataset.csrfToken
			});
			const verifyUrl = withQueryParams(app.dataset.registrationVerifyUrl, {
				device_name: deviceName,
				csrf_token: app.dataset.csrfToken
			});
			const success = await registerPasskey(app.dataset.username, optionsUrl, verifyUrl);
			if (!success) {
				throw new Error(messages.registrationFailed);
			}
			passkeyNameInput.value = '';
			showStatus('success', messages.registrationSuccess);
			await loadPasskeys();
		} catch (error) {
			showStatus('error', messages.registrationError + ': ' + (error.message || messages.registrationFailed));
		} finally {
			registerButton.disabled = false;
		}
	});

	passkeyContent.hidden = false;
	loadPasskeys();
	Promise.resolve(isWebAuthnAvailable()).then(function (supported) {
		compatibilityCheck.hidden = true;
		if (!supported) {
			notSupportedMessage.textContent = messages.notSupported;
			notSupportedMessage.hidden = false;
			registerButton.disabled = true;
		}
	}).catch(function (error) {
		compatibilityCheck.hidden = true;
		notSupportedMessage.textContent = messages.notSupported;
		notSupportedMessage.hidden = false;
		registerButton.disabled = true;
		console.error('Error checking WebAuthn support:', error);
	});

	let inactivityTimeout;
	const resetInactivityTimer = function () {
		window.clearTimeout(inactivityTimeout);
		inactivityTimeout = window.setTimeout(function () {
			if (window.confirm(messages.sessionExpired)) {
				window.location.reload();
			}
		}, 15 * 60 * 1000);
	};
	['mousedown', 'keypress', 'scroll', 'touchstart'].forEach(function (eventName) {
		document.addEventListener(eventName, resetInactivityTimer, true);
	});
	resetInactivityTimer();
});
