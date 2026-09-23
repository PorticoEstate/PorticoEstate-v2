document.addEventListener('DOMContentLoaded', function () {
	const app = document.getElementById('messenger-view-app');
	fetch(app.dataset.apiUrl, {headers: {'Accept': 'application/json'}}).then(response => { if (!response.ok) throw new Error(); return response.json(); }).then(message => {
		document.getElementById('messenger-view-from').textContent = message.from || '';
		document.getElementById('messenger-view-subject').textContent = message.subject || '';
		document.getElementById('messenger-view-date').textContent = message.date || '';
		document.getElementById('messenger-view-content').textContent = message.content || '';
	}).catch(() => { const error = document.getElementById('messenger-view-error'); error.textContent = 'Unable to load message.'; error.hidden = false; });
});
