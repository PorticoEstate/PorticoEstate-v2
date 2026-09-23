document.addEventListener('DOMContentLoaded', function () {
	const app = document.getElementById('messenger-forward-app');
	const form = document.getElementById('messenger-forward-form');
	const to = document.getElementById('messenger-forward-to');
	fetch(app.dataset.usersUrl).then(response => response.json()).then(payload => {
		(payload.data || []).forEach(user => to.add(new Option(user.name, user.id)));
		if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.select2 !== 'undefined') {
			window.jQuery(to).select2({
				width: '100%',
				minimumResultsForSearch: 0,
				placeholder: app.dataset.placeholder || '',
				allowClear: true
			});
		}
	});
	fetch(app.dataset.apiUrl).then(response => response.json()).then(message => { document.getElementById('messenger-forward-subject').value = 'FW: ' + (message.subject || ''); document.getElementById('messenger-forward-content').value = message.content || ''; });
	form.addEventListener('submit', function (event) { event.preventDefault(); const payload = Object.fromEntries(new FormData(form)); payload.csrf_name = app.dataset.csrfName; payload.csrf_value = app.dataset.csrfValue; fetch(app.dataset.actionUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'csrf_name': app.dataset.csrfName, 'csrf_value': app.dataset.csrfValue}, body: JSON.stringify(payload)}).then(response => response.json().then(data => ({ok: response.ok, data: data}))).then(result => { if (!result.ok) { const error = document.getElementById('messenger-forward-error'); error.textContent = (result.data.errors || [result.data.error]).join('\n'); error.hidden = false; return; } window.location.href = app.dataset.backUrl; }); });
});
