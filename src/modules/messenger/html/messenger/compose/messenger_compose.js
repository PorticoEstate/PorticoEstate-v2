document.addEventListener('DOMContentLoaded', function () {
	const app = document.getElementById('messenger-compose-app');
	const form = document.getElementById('messenger-compose-form');
	const error = document.getElementById('messenger-compose-error');
	fetch(app.dataset.usersUrl).then(response => response.json()).then(payload => (payload.data || []).forEach(user => document.getElementById('messenger-to').add(new Option(user.name, user.id))));
	form.addEventListener('submit', function (event) {
		event.preventDefault();
		fetch(app.dataset.apiUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'csrf_name': app.dataset.csrfName, 'csrf_value': app.dataset.csrfValue}, body: JSON.stringify(Object.fromEntries(new FormData(form)))}).then(response => response.json().then(data => ({ok: response.ok, data: data}))).then(result => { if (!result.ok) { error.textContent = (result.data.errors || [result.data.error]).join('\n'); error.hidden = false; return; } window.location.href = app.dataset.backUrl; });
	});
});
