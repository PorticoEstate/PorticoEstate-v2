document.addEventListener('DOMContentLoaded', function () {
	const app = document.getElementById('messenger-forward-app');
	const form = document.getElementById('messenger-forward-form');
	const to = document.getElementById('messenger-forward-to');
	fetch(app.dataset.usersUrl).then(response => response.json()).then(payload => (payload.data || []).forEach(user => to.add(new Option(user.name, user.id))));
	fetch(app.dataset.apiUrl).then(response => response.json()).then(message => { document.getElementById('messenger-forward-subject').value = 'FW: ' + (message.subject || ''); document.getElementById('messenger-forward-content').value = message.content || ''; });
	form.addEventListener('submit', function (event) { event.preventDefault(); fetch(app.dataset.actionUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'csrf_name': app.dataset.csrfName, 'csrf_value': app.dataset.csrfValue}, body: JSON.stringify(Object.fromEntries(new FormData(form)))}).then(response => response.json().then(data => ({ok: response.ok, data: data}))).then(result => { if (!result.ok) { const error = document.getElementById('messenger-forward-error'); error.textContent = (result.data.errors || [result.data.error]).join('\n'); error.hidden = false; return; } window.location.href = app.dataset.backUrl; }); });
});
