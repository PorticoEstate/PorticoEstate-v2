window.emailMessageAction = async function (root, payload)
{
	const response = await fetch(root.dataset.actionUrl, {
		method: 'POST',
		headers: {
			Accept: 'application/json', 'Content-Type': 'application/json',
			'X-CSRF-NAME': root.dataset.csrfName, 'X-CSRF-VALUE': root.dataset.csrfValue
		},
		body: JSON.stringify(payload)
	});
	const result = await response.json();
	if (!response.ok || !Array.isArray(result.completed) || !Array.isArray(result.failed))
	{
		throw new Error(result.error || 'Unable to complete message action');
	}
	return result;
};