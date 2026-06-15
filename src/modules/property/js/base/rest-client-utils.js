(function (global)
{
	'use strict';

	function parseURL(url)
	{
		var parser = document.createElement('a');
		var searchObject = {};
		var queries;
		var split;
		var i;

		parser.href = url;
		queries = parser.search.replace(/^\?/, '').split('&');
		for (i = 0; i < queries.length; i++)
		{
			if (!queries[i])
			{
				continue;
			}
			split = queries[i].split('=');
			searchObject[split[0]] = split[1];
		}

		return {
			protocol: parser.protocol,
			host: parser.host,
			hostname: parser.hostname,
			port: parser.port,
			pathname: parser.pathname,
			search: parser.search,
			searchObject: searchObject,
			hash: parser.hash
		};
	}

	function parseFormKeyTokens(key)
	{
		var tokens = [];
		var match;
		var regex = /([^\[\]]+)/g;
		while ((match = regex.exec(key)) !== null)
		{
			tokens.push(match[1]);
		}
		return tokens;
	}

	function setNestedValue(target, key, value)
	{
		var tokens = parseFormKeyTokens(key);
		var forceArray = /\[\]$/.test(key);
		var node;
		var i;

		if (!tokens.length)
		{
			return;
		}

		node = target;
		for (i = 0; i < tokens.length - 1; i++)
		{
			var token = tokens[i];
			if (!Object.prototype.hasOwnProperty.call(node, token) || typeof node[token] !== 'object' || node[token] === null)
			{
				node[token] = {};
			}
			node = node[token];
		}

		var leaf = tokens[tokens.length - 1];
		if (!Object.prototype.hasOwnProperty.call(node, leaf))
		{
			node[leaf] = forceArray ? [value] : value;
			return;
		}

		if (Array.isArray(node[leaf]))
		{
			node[leaf].push(value);
			return;
		}

		node[leaf] = [node[leaf], value];
	}

	function formDataToObject(formData)
	{
		var payload = {};
		formData.forEach(function (value, key)
		{
			setNestedValue(payload, key, value);
		});
		return payload;
	}

	function clearFormAlerts(form, selector)
	{
		var notices = form.querySelectorAll(selector || '.rest-submit-alert');
		for (var i = 0; i < notices.length; i++)
		{
			notices[i].remove();
		}
	}

	function normalizeMessages(messages)
	{
		if (Array.isArray(messages))
		{
			return messages;
		}

		if (messages === null || messages === undefined || messages === '')
		{
			return [];
		}

		return [String(messages)];
	}

	function renderFormAlert(form, messages, options)
	{
		options = options || {};
		var normalizedMessages = normalizeMessages(messages);
		var selector = options.selector || '.rest-submit-alert';
		var className = options.className || 'rest-submit-alert';
		var role = (options.role === false) ? '' : (options.role || 'alert');
		var useList = !!options.useList;

		if (!form)
		{
			if (normalizedMessages.length)
			{
				global.alert(normalizedMessages[0]);
			}
			return;
		}

		clearFormAlerts(form, selector);

		var alert = document.createElement('div');
		alert.className = className;
		if (role)
		{
			alert.setAttribute('role', role);
		}

		if (options.headingText)
		{
			var headingTag = options.headingTag || 'strong';
			var heading = document.createElement(headingTag);
			heading.textContent = options.headingText;
			alert.appendChild(heading);
		}

		if (useList)
		{
			var list = document.createElement('ul');
			for (var i = 0; i < normalizedMessages.length; i++)
			{
				var item = document.createElement('li');
				item.textContent = normalizedMessages[i];
				list.appendChild(item);
			}
			alert.appendChild(list);
		}
		else
		{
			for (var j = 0; j < normalizedMessages.length; j++)
			{
				if (j > 0)
				{
					alert.appendChild(document.createElement('br'));
				}
				alert.appendChild(document.createTextNode(normalizedMessages[j]));
			}
		}

		form.insertBefore(alert, form.firstChild);
	}

	global.PorticoClientUtils = global.PorticoClientUtils || {
		parseURL: parseURL,
		parseFormKeyTokens: parseFormKeyTokens,
		setNestedValue: setNestedValue,
		formDataToObject: formDataToObject,
		clearFormAlerts: clearFormAlerts,
		renderFormAlert: renderFormAlert
	};
})(window);