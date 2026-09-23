document.addEventListener('DOMContentLoaded', function () {
    const app = document.getElementById('messenger-delete-app');
    document.getElementById('messenger-delete-confirm').addEventListener('click', function () {
        fetch(app.dataset.apiUrl, {method: 'DELETE', headers: {'Content-Type': 'application/json', 'csrf_name': app.dataset.csrfName, 'csrf_value': app.dataset.csrfValue}, body: JSON.stringify({ids: [Number(app.dataset.messageId)], csrf_name: app.dataset.csrfName, csrf_value: app.dataset.csrfValue})}).then(response => { if (response.ok) window.location.href = app.dataset.inboxUrl; else document.getElementById('messenger-delete-error').hidden = false; });
    });
});