document.addEventListener('DOMContentLoaded', function () {
	const app = document.getElementById('messenger-compose-groups-app');
	const form = document.getElementById('messenger-compose-groups-form');
	const groups = document.getElementById('messenger-groups');
	const error = document.getElementById('messenger-compose-groups-error');
	fetch(app.dataset.apiUrl, {headers: {'Accept': 'application/json'}})
		.then(response => { if (!response.ok) throw new Error('Unable to load groups.'); return response.json(); })
		.then(payload => {
			(payload.data || []).forEach(group => groups.add(new Option(group.name, group.id)));
			if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.select2 !== 'undefined') {
				window.jQuery(groups).select2({width: '100%', closeOnSelect: false, minimumResultsForSearch: 0, placeholder: app.dataset.placeholder || '', allowClear: true});
			}
		})
		.catch(reason => { error.textContent = reason.message; error.hidden = false; });
	form.addEventListener('submit', function (event) {
		event.preventDefault();
		const payload = Object.fromEntries(new FormData(form).entries());
		payload.account_groups = Array.from(groups.selectedOptions).map(option => option.value).filter(Boolean);
		fetch(app.dataset.actionUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'csrf_name': app.dataset.csrfName, 'csrf_value': app.dataset.csrfValue}, body: JSON.stringify(payload)})
			.then(response => response.json().then(data => ({ok: response.ok, data: data})))
			.then(result => { if (!result.ok) { error.textContent = (result.data.errors || [result.data.error]).join('\n'); error.hidden = false; return; } window.location.href = app.dataset.backUrl; });
	});
});
