document.addEventListener('DOMContentLoaded', function () {
	var app = document.getElementById('sms-send-group-app');
	var form = document.getElementById('sms-send-group-form');
	var error = document.getElementById('sms-send-group-error');
	var message = document.getElementById('sms-group-message');
	var counter = document.getElementById('sms-group-char-count');
	var maxLength = parseInt(app.dataset.maxLength, 10) || 804;

	function updateCounter() { counter.textContent = Math.max(0, maxLength - message.value.length); }
	message.addEventListener('input', updateCounter);
	updateCounter();
	form.addEventListener('submit', function (event) {
		event.preventDefault();
		error.hidden = true;
		var payload = {
			group: document.getElementById('sms-group').value,
			message: message.value,
			flash: document.getElementById('sms-group-flash').checked,
			csrf_name: app.dataset.csrfName,
			csrf_value: app.dataset.csrfValue
		};
		fetch(app.dataset.apiUrl, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)})
			.then(function (response) { return response.json().then(function (data) { return {ok: response.ok, data: data}; }); })
			.then(function (result) {
				if (!result.ok) { error.textContent = result.data.error || 'Failed to send SMS'; error.hidden = false; return; }
				window.location.href = app.dataset.backUrl;
			})
			.catch(function () { error.textContent = 'Failed to send SMS'; error.hidden = false; });
	});
});
