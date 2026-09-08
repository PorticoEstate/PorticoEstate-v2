document.addEventListener('DOMContentLoaded', function () {
	var app = document.getElementById('sms-command-edit');
	var form = document.getElementById('sms-command-form');
	var error = document.getElementById('sms-command-error');

	form.addEventListener('submit', function (event) {
		event.preventDefault();

		var payload = {
			code: document.getElementById('command-code').value,
			type: document.getElementById('command-type').value,
			exec: document.getElementById('command-exec').value,
			descr: document.getElementById('command-descr').value,
			csrf_name: app.dataset.csrfName,
			csrf_value: app.dataset.csrfValue
		};

		fetch(app.dataset.apiUrl, {
			method: app.dataset.id > 0 ? 'PUT' : 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify(payload)
		})
			.then(function (response) {
				return response.json().then(function (data) {
					return {ok: response.ok, data: data};
				});
			})
			.then(function (result) {
				if (!result.ok) {
					error.textContent = result.data.error || 'Failed';
					error.hidden = false;
					return;
				}
				window.location.href = app.dataset.listUrl;
			});
	});
});
