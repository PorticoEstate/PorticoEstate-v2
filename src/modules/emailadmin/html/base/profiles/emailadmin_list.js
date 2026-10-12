(function ()
{
	const root = document.getElementById('emailadmin-list');
	if (!root) return;

	const apiUrl = root.dataset.apiUrl;
	const editTemplate = root.dataset.editUrl;
	const tbody = document.getElementById('emailadmin-profile-rows');
	const alertBox = document.getElementById('emailadmin-error');
	const csrfHeaders = { [root.dataset.csrfName]: root.dataset.csrfValue };
	let profiles = [];

	function showError(message)
	{
		alertBox.textContent = message;
		alertBox.hidden = false;
	}

	function cell(row, value)
	{
		const element = document.createElement('td');
		element.textContent = value == null ? '' : String(value);
		row.appendChild(element);
		return element;
	}

	function actionButton(label, action, disabled)
	{
		const button = document.createElement('button');
		button.type = 'button';
		button.textContent = label;
		button.disabled = Boolean(disabled);
		button.addEventListener('click', action);
		return button;
	}

	function render()
	{
		tbody.replaceChildren();
		if (!profiles.length)
		{
			const row = document.createElement('tr');
			const empty = cell(row, 'No profiles found');
			empty.colSpan = 9;
			tbody.appendChild(row);
			return;
		}

		profiles.forEach((profile, index) =>
		{
			const row = document.createElement('tr');
			cell(row, profile.description);
			cell(row, profile.smtpServer);
			cell(row, profile.imapServer);
			cell(row, profile.ea_appname);
			cell(row, profile.ea_group);
			cell(row, profile.ea_user);
			cell(row, profile.ea_active ? 'Yes' : 'No');
			const order = document.createElement('td');
			const orderActions = document.createElement('div');
			orderActions.className = 'emailadmin-row-actions';
			orderActions.append(
				actionButton('↑', () => move(index, -1), index === 0),
				actionButton('↓', () => move(index, 1), index === profiles.length - 1)
			);
			order.appendChild(orderActions);
			row.appendChild(order);
			const actions = document.createElement('td');
			const actionRow = document.createElement('div');
			actionRow.className = 'emailadmin-row-actions';
			const edit = document.createElement('a');
			edit.className = 'emailadmin-button';
			edit.textContent = 'Edit';
			edit.href = editTemplate.replace('__PROFILE_ID__', encodeURIComponent(profile.profileID));
			actionRow.appendChild(edit);
			actionRow.appendChild(actionButton('Delete', () => remove(profile), false));
			actions.appendChild(actionRow);
			row.appendChild(actions);
			tbody.appendChild(row);
		});
	}

	async function load()
	{
		try
		{
			const response = await fetch(apiUrl, { headers: { Accept: 'application/json' } });
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to load profiles');
			profiles = result.items || [];
			render();
		} catch (error)
		{
			showError(error.message);
		}
	}

	async function remove(profile)
	{
		if (!window.confirm('Delete profile "' + profile.description + '"?')) return;
		try
		{
			const response = await fetch(apiUrl + '/' + profile.profileID, { method: 'DELETE', headers: csrfHeaders });
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Delete failed');
			await load();
		} catch (error)
		{
			showError(error.message);
		}
	}

	async function move(index, offset)
	{
		const target = index + offset;
		[profiles[index], profiles[target]] = [profiles[target], profiles[index]];
		render();
		try
		{
			const response = await fetch(apiUrl + '/order', {
				method: 'PATCH',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders },
				body: JSON.stringify({ profile_ids: profiles.map(profile => profile.profileID) })
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Unable to save order');
		} catch (error)
		{
			showError(error.message);
			await load();
		}
	}

	load();
})();