(function ()
{
	const root = document.getElementById('email-accounts');
	if (!root) return;

	const rows = document.getElementById('email-accounts-rows');
	const status = document.getElementById('email-accounts-status');
	const error = document.getElementById('email-accounts-error');

	async function load()
	{
		try
		{
			const response = await fetch(root.dataset.apiUrl, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.reason || result.error || 'Unable to load accounts');
			rows.replaceChildren();
			const accounts = result.items || [];
			if (!accounts.length)
			{
				const row = document.createElement('tr');
				const empty = document.createElement('td');
				empty.colSpan = 3;
				empty.textContent = 'No extra email accounts';
				row.appendChild(empty);
				rows.appendChild(row);
			}
			accounts.forEach(account =>
			{
				const row = document.createElement('tr');
				const name = document.createElement('td');
				name.textContent = account.name;
				const accountStatus = document.createElement('td');
				accountStatus.textContent = account.status;
				const actions = document.createElement('td');
				actions.className = 'email-accounts-actions';
				const edit = document.createElement('a');
				edit.href = phpGWLink('email/view/account', { ex_acctnum: account.id });
				edit.textContent = 'Edit';
				const remove = document.createElement('button');
				remove.type = 'button';
				remove.textContent = 'Delete';
				remove.addEventListener('click', async () =>
				{
					if (!window.confirm('Delete email account "' + account.name + '"?')) return;
					remove.disabled = true;
					try
					{
						const deleteResponse = await fetch(root.dataset.actionUrl, {
							method: 'POST',
							headers: {
								Accept: 'application/json',
								'Content-Type': 'application/json',
								'X-CSRF-NAME': root.dataset.csrfName,
								'X-CSRF-VALUE': root.dataset.csrfValue
							},
							body: JSON.stringify({ id: account.id })
						});
						const deleteResult = await deleteResponse.json();
						if (!deleteResponse.ok) throw new Error(deleteResult.reason || deleteResult.error || 'Unable to delete account');
						await load();
					} catch (exception)
					{
						error.textContent = exception.message;
						error.hidden = false;
						remove.disabled = false;
					}
				});
				actions.append(edit, remove);
				row.append(name, accountStatus, actions);
				rows.appendChild(row);
			});
			status.textContent = accounts.length + ' accounts';
		} catch (exception)
		{
			status.textContent = '';
			error.textContent = exception.message;
			error.hidden = false;
		}
	}

	load();
})();
