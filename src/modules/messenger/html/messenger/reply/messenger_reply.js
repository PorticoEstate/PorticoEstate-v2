document.addEventListener('DOMContentLoaded', function () {
	const app = document.getElementById('messenger-reply-app');
	const form = document.getElementById('messenger-reply-form');
	const to = document.getElementById('messenger-reply-to');
	fetch(app.dataset.usersUrl).then(response => response.json()).then(payload => (payload.data || []).forEach(user => to.add(new Option(user.name, user.id))));
	fetch(app.dataset.apiUrl).then(response => response.json()).then(message => { to.value = message.from_id || ''; document.getElementById('messenger-reply-subject').value = 'RE: ' + (message.subject || ''); document.getElementById('messenger-reply-content').value = message.content || ''; });
	form.addEventListener('submit', function (event) { event.preventDefault(); fetch(app.dataset.actionUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'csrf_name': app.dataset.csrfName, 'csrf_value': app.dataset.csrfValue}, body: JSON.stringify(Object.fromEntries(new FormData(form)))}).then(response => response.json().then(data => ({ok: response.ok, data: data}))).then(result => { if (!result.ok) { const error = document.getElementById('messenger-reply-error'); error.textContent = (result.data.errors || [result.data.error]).join('\n'); error.hidden = false; return; } window.location.href = app.dataset.backUrl; }); });
});
