document.addEventListener('DOMContentLoaded', function () {
	var app = document.getElementById('sms-delete-app');
	document.getElementById('sms-delete-confirm').addEventListener('click', function () {
		fetch(app.dataset.apiUrl, {
			method: 'DELETE',
			headers: {
				'Content-Type': 'application/json',
				'csrf_name': app.dataset.csrfName,
				'csrf_value': app.dataset.csrfValue
			},
			body: JSON.stringify({csrf_name: app.dataset.csrfName, csrf_value: app.dataset.csrfValue})
		}).then(function (response) {
			if (response.ok) {
				window.location.href = app.dataset.listUrl;
			} else {
				document.getElementById('sms-delete-error').hidden = false;
			}
		});
	});
});
