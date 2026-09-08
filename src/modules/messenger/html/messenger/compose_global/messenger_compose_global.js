document.addEventListener('DOMContentLoaded', function () {
	const app = document.getElementById('messenger-compose-global-app');
	const form = document.getElementById('messenger-compose-global-form');
	const error = document.getElementById('messenger-compose-global-error');
	form.addEventListener('submit', function (event) {
		event.preventDefault();
		const payload = Object.fromEntries(new FormData(form));
		payload.csrf_name = app.dataset.csrfName;
		payload.csrf_value = app.dataset.csrfValue;
		fetch(app.dataset.apiUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'csrf_name': app.dataset.csrfName, 'csrf_value': app.dataset.csrfValue}, body: JSON.stringify(payload)})
			.then(response => response.json().then(data => ({ok: response.ok, data: data})))
			.then(result => { if (!result.ok) { error.textContent = (result.data.errors || [result.data.error]).join('\n'); error.hidden = false; return; } window.location.href = app.dataset.backUrl; });
	});
});
