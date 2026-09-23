document.addEventListener('DOMContentLoaded', function () {
	const app = document.getElementById('messenger-compose-app');
	const form = document.getElementById('messenger-compose-form');
	const error = document.getElementById('messenger-compose-error');
	const recipient = document.getElementById('messenger-to');
	fetch(app.dataset.usersUrl, {headers: {'Accept': 'application/json'}})
		.then(response => { if (!response.ok) throw new Error('Unable to load recipients.'); return response.json(); })
		.then(payload => {
			(payload.data || []).forEach(user => recipient.add(new Option(user.name, user.id)));
			if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.select2 !== 'undefined') {
				window.jQuery(recipient).select2({
					width: '100%',
					minimumResultsForSearch: 0,
					placeholder: app.dataset.placeholder || '',
					allowClear: true
				});
			}
		})
		.catch(reason => { error.textContent = reason.message; error.hidden = false; });
	form.addEventListener('submit', function (event) {
		event.preventDefault();
		const payload = Object.fromEntries(new FormData(form));
		payload.csrf_name = app.dataset.csrfName;
		payload.csrf_value = app.dataset.csrfValue;
		fetch(app.dataset.apiUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'csrf_name': app.dataset.csrfName, 'csrf_value': app.dataset.csrfValue}, body: JSON.stringify(payload)}).then(response => response.json().then(data => ({ok: response.ok, data: data}))).then(result => { if (!result.ok) { error.textContent = (result.data.errors || [result.data.error]).join('\n'); error.hidden = false; return; } window.location.href = app.dataset.backUrl; });
	});
});
