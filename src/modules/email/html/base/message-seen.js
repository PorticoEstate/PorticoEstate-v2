window.emailSetSeen = async function (root, message, seen)
{
	const response = await fetch(root.dataset.seenUrl, {
		method: 'POST',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			'X-CSRF-NAME': root.dataset.csrfName,
			'X-CSRF-VALUE': root.dataset.csrfValue
		},
		body: JSON.stringify({
			msgball: { acctnum: message.account, folder: message.sourceFolder, msgnum: message.messageNumber },
			seen
		})
	});
	const result = await response.json();
	if (!response.ok || result.updated !== true || result.seen !== seen)
	{
		throw new Error(result.reason || result.error || 'Unable to update read status');
	}
	return result.seen;
};