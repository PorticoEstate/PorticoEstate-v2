(function ()
{
	const root = document.getElementById('emailadmin-editor');
	if (!root) return;

	const apiUrl = root.dataset.apiUrl;
	const profileId = Number(root.dataset.profileId || 0);
	const options = JSON.parse(root.dataset.options || '{}');
	const form = document.getElementById('emailadmin-profile-form');
	const alertBox = document.getElementById('emailadmin-error');
	const userSelect = document.getElementById('emailadmin-user');
	const userEditLink = document.getElementById('emailadmin-user-edit-link');
	const csrfHeaders = {
		'X-CSRF-NAME': root.dataset.csrfName,
		'X-CSRF-VALUE': root.dataset.csrfValue
	};
	const checkboxFields = new Set([
		'smtpAuth', 'editforwardingaddress', 'smtpLDAPUseDefault', 'imapEnableCyrusAdmin',
		'imapEnableSieve', 'imapoldcclient'
	]);
	const labels = {
		smtpServer: 'SMTP server', smtpPort: 'SMTP port', smtpAuth: 'Use SMTP authentication',
		ea_smtp_auth_username: 'SMTP username', ea_smtp_auth_password: 'SMTP password',
		editforwardingaddress: 'Allow forwarding address edits', smtpLDAPServer: 'LDAP server',
		smtpLDAPAdminDN: 'LDAP admin DN', smtpLDAPAdminPW: 'LDAP admin password',
		smtpLDAPBaseDN: 'LDAP base DN', smtpLDAPUseDefault: 'Use LDAP defaults',
		imapServer: 'IMAP server', imapPort: 'IMAP port', imapLoginType: 'Login type',
		imapTLSEncryption: 'Connection encryption', imapTLSAuthentication: 'Do not validate certificate',
		imapoldcclient: 'Legacy IMAP client', imapAuthUsername: 'IMAP username',
		imapAuthPassword: 'IMAP password', imapEnableCyrusAdmin: 'Enable Cyrus administration',
		imapAdminUsername: 'Admin username', imapAdminPW: 'Admin password', imapEnableSieve: 'Enable Sieve',
		imapSieveServer: 'Sieve server', imapSievePort: 'Sieve port'
	};

	function fail(message)
	{
		alertBox.textContent = message;
		alertBox.hidden = false;
	}

	function fieldName(group, type, key)
	{
		return group + '[' + type + '][' + key + ']';
	}

	function addOptions(select, list, includeAny, selected)
	{
		if (includeAny)
		{
			const option = new Option(root.dataset.anyApplication, '');
			select.add(option);
		}
		(list || []).forEach(item =>
		{
			const option = new Option(item.name, item.id);
			option.disabled = Boolean(item.disabled);
			select.add(option);
		});
		select.value = selected == null ? (includeAny ? '' : '0') : String(selected);
	}

	function makeSearchable(select)
	{
		if (typeof window.jQuery === 'undefined' || typeof window.jQuery.fn.select2 === 'undefined') return;
		window.jQuery(select).select2({ width: '100%', minimumResultsForSearch: 0 });
	}

	function updateUserEditLink()
	{
		const userId = userSelect.value;
		if (!userId || Number(userId) <= 0)
		{
			userEditLink.hidden = true;
			userEditLink.removeAttribute('href');
			return;
		}

		userEditLink.href = root.dataset.userEditUrlTemplate.replace('__USER_ID__', encodeURIComponent(userId));
		userEditLink.hidden = false;
	}

	userSelect.addEventListener('change', updateUserEditLink);

	function makeInput(container, group, type, key, value)
	{
		const label = document.createElement('label');
		label.appendChild(document.createTextNode(labels[key] || key.replace(/([A-Z])/g, ' $1').replace(/^./, first => first.toUpperCase())));
		const name = fieldName(group, type, key);
		if (checkboxFields.has(key))
		{
			const wrapper = document.createElement('span');
			wrapper.className = 'emailadmin-inline';
			const input = document.createElement('input');
			input.type = 'checkbox';
			input.name = name;
			input.value = 'yes';
			input.checked = value === true || value === 1 || value === '1' || value === 'yes';
			wrapper.appendChild(input);
			label.appendChild(wrapper);
		} else if (key === 'imapTLSAuthentication')
		{
			const wrapper = document.createElement('span');
			wrapper.className = 'emailadmin-inline';
			const input = document.createElement('input');
			input.type = 'checkbox';
			input.name = name;
			input.value = 'dontvalidate';
			input.checked = value === false || value === 0 || value === '0' || value === '';
			wrapper.appendChild(input);
			label.appendChild(wrapper);
		} else if (key === 'imapTLSEncryption')
		{
			const wrapper = document.createElement('span');
			wrapper.className = 'emailadmin-inline';
			[['0', 'None'], ['1', 'STARTTLS'], ['2', 'TLS'], ['3', 'SSL']].forEach(([optionValue, caption]) =>
			{
				const optionLabel = document.createElement('span');
				const input = document.createElement('input');
				input.type = 'radio';
				input.name = name;
				input.value = optionValue;
				input.checked = String(value == null ? 0 : value) === optionValue;
				optionLabel.append(input, document.createTextNode(caption));
				wrapper.appendChild(optionLabel);
			});
			label.appendChild(wrapper);
		} else if (key === 'imapLoginType')
		{
			const select = document.createElement('select');
			select.name = name;
			[['standard', 'Username'], ['vmailmgr', 'Username@domain'], ['admin', 'Admin credentials']].forEach(([id, caption]) => select.add(new Option(caption, id)));
			select.value = value || 'standard';
			label.appendChild(select);
		} else
		{
			const input = document.createElement('input');
			input.type = /password|PW$/.test(key) ? 'password' : 'text';
			input.name = name;
			input.value = input.type === 'password' ? '' : (value == null ? '' : String(value));
			if (input.type === 'password') input.autocomplete = 'new-password';
			label.appendChild(input);
		}
		container.appendChild(label);
	}

	function renderServerFields(server, profile)
	{
		const select = document.getElementById('emailadmin-' + server + '-type');
		const container = document.getElementById('emailadmin-' + server + '-fields');
		const source = options[server] || {};
		Object.entries(source).forEach(([id, data]) => select.add(new Option(data.name, id)));
		const typeField = server === 'smtp' ? 'smtpType' : 'imapType';
		select.value = String(profile[typeField] || (server === 'smtp' ? 1 : 2));
		const draw = () =>
		{
			container.replaceChildren();
			const type = select.value;
			const fields = source[type] ? source[type].fields : [];
			const defaults = { smtpPort: '25', imapPort: '143', imapSievePort: '2000', imapTLSEncryption: 0, imapTLSAuthentication: 1 };
			fields.filter(key => key !== typeField).forEach(key => makeInput(container, server + 'settings', type, key, profile[key] == null ? defaults[key] : profile[key]));
		};
		select.addEventListener('change', draw);
		draw();
	}

	function fillGlobals(profile)
	{
		addOptions(document.getElementById('emailadmin-app'), options.apps, true, profile.ea_appname || '');
		addOptions(document.getElementById('emailadmin-group'), options.groups, false, profile.ea_group || 0);
		addOptions(document.getElementById('emailadmin-user'), options.users, false, profile.ea_user || 0);
		makeSearchable(document.getElementById('emailadmin-group'));
		makeSearchable(userSelect);
		updateUserEditLink();
		Object.entries(profile).forEach(([key, value]) =>
		{
			const input = form.elements.namedItem('globalsettings[' + key + ']');
			if (!input) return;
			if (input.type === 'checkbox') input.checked = value === true || value === 1 || value === '1' || value === 'yes';
			else if (input.tagName !== 'SELECT') input.value = value == null ? '' : String(value);
		});
	}

	function payload()
	{
		const data = new FormData(form);
		const result = { globalsettings: {}, smtpsettings: {}, imapsettings: {} };
		for (const [name, value] of data.entries())
		{
			const match = name.match(/^(globalsettings|smtpsettings|imapsettings)(?:\[(\d+)\])?\[([^\]]+)\]$/);
			if (!match) continue;
			const [, group, type, key] = match;
			if (!type) result[group][key] = value;
			else
			{
				if (!result[group][type]) result[group][type] = {};
				result[group][type][key] = value;
			}
		}
		['ea_active', 'userDefinedIdentities', 'userDefinedAccounts', 'ea_user_defined_signatures'].forEach(key =>
		{
			const input = form.elements.namedItem('globalsettings[' + key + ']');
			if (input) result.globalsettings[key] = input.checked ? 'yes' : '';
		});
		['smtpAuth', 'editforwardingaddress', 'smtpLDAPUseDefault', 'imapEnableCyrusAdmin', 'imapEnableSieve', 'imapoldcclient'].forEach(key =>
		{
			const input = form.querySelector('[name$="[' + key + ']"]');
			if (input)
			{
				const parent = input.name.match(/^(smtpsettings|imapsettings)\[(\d+)\]/);
				if (parent) result[parent[1]][parent[2]][key] = input.checked ? 'yes' : '';
			}
		});
		return result;
	}

	async function loadProfile()
	{
		if (!profileId) return {};
		const response = await fetch(apiUrl, { headers: { Accept: 'application/json' } });
		const result = await response.json();
		if (!response.ok) throw new Error(result.error || 'Unable to load profile');
		return result.item || {};
	}

	async function initialize()
	{
		try
		{
			const profile = await loadProfile();
			fillGlobals(profile);
			renderServerFields('smtp', profile);
			renderServerFields('imap', profile);
		} catch (error)
		{
			fail(error.message);
		}
	}

	form.addEventListener('submit', async event =>
	{
		event.preventDefault();
		alertBox.hidden = true;
		const smtpType = document.getElementById('emailadmin-smtp-type').value;
		const imapType = document.getElementById('emailadmin-imap-type').value;
		const body = payload();
		body.smtpsettings.smtpType = smtpType;
		body.imapsettings.imapType = imapType;
		try
		{
			const saveButton = form.querySelector('[type="submit"]');
			saveButton.disabled = true;
			const response = await fetch(apiUrl, {
				method: profileId ? 'PUT' : 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders },
				body: JSON.stringify(body)
			});
			const result = await response.json();
			if (!response.ok) throw new Error(result.error || 'Save failed');
			window.location.assign(root.querySelector('.emailadmin-heading a').href);
		} catch (error)
		{
			fail(error.message);
		} finally
		{
			const saveButton = form.querySelector('[type="submit"]');
			saveButton.disabled = false;
		}
	});

	initialize();
})();