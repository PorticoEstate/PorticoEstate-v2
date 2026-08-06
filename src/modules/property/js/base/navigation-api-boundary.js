(function (global)
{
	'use strict';

	var clickHistoryStore = global.__porticoClickHistoryStore || {};
	global.__porticoClickHistoryStore = clickHistoryStore;

	function generateClickHistoryToken()
	{
		return String(Date.now()) + '-' + Math.random().toString(36).slice(2);
	}

	function getStoreKey(prefix, form)
	{
		var action = (form && form.action) ? String(form.action) : '';
		return prefix + '|' + action;
	}

	function resolveInitialToken(query, deps)
	{
		var token = (query && query.click_history) ? String(query.click_history) : '';
		if (!token && typeof global.strBaseURL !== 'undefined' && global.strBaseURL)
		{
			token = ((deps.parseURL(global.strBaseURL).searchObject || {}).click_history || '').toString();
		}
		return token || generateClickHistoryToken();
	}

	function getClickHistoryToken(storeKey, query, deps)
	{
		if (!clickHistoryStore[storeKey])
		{
			clickHistoryStore[storeKey] = resolveInitialToken(query, deps);
		}
		return clickHistoryStore[storeKey];
	}

	function refreshClickHistoryToken(storeKey)
	{
		clickHistoryStore[storeKey] = generateClickHistoryToken();
		return clickHistoryStore[storeKey];
	}

	function createLocationClients(form, deps)
	{
		var parsed = deps.parseURL(form.action);
		var query = parsed.searchObject || {};
		var tokenStoreKey = getStoreKey('location', form);

		function buildEditUrl(locationCode)
		{
			var typeId = query.type_id || '';
			var lookupTenant = query.lookup_tenant || '';
			var params = {
				menuaction: 'property.uilocation.edit',
				location_code: locationCode
			};

			if (typeId)
			{
				params.type_id = typeId;
			}
			if (lookupTenant)
			{
				params.lookup_tenant = lookupTenant;
			}

			return global.phpGWLink('index.php', params);
		}

		function buildSaveRequest()
		{
			var queryParts = [];
			var originalLocationCode = (
				query.location_code
				|| deps.getLocationFieldValue(form, 'input[name="location_code"]')
				|| deps.getLocationFieldValue(form, 'input[name="values[location_code]"]')
				|| ''
			).trim();

			if (!originalLocationCode)
			{
				var pathMatch = parsed.pathname ? parsed.pathname.match(/\/property\/location\/([^\/?#]+)/) : null;
				if (pathMatch && pathMatch[1])
				{
					originalLocationCode = decodeURIComponent(pathMatch[1]);
				}
			}
			var rawLocationId = '';

			if (typeof global.location_id !== 'undefined' && global.location_id !== null)
			{
				rawLocationId = String(global.location_id);
			}

			var routeLocationId = parseInt(rawLocationId, 10);
			var hasExistingLocation = (!isNaN(routeLocationId) && routeLocationId > 0) || !!originalLocationCode;
			var isUpdate = hasExistingLocation && !!originalLocationCode;
			var requestUrl = isUpdate
				? '/property/location/' + encodeURIComponent(originalLocationCode)
				: '/property/location';

			var clickHistory = getClickHistoryToken(tokenStoreKey, query, deps);
			if (clickHistory)
			{
				queryParts.push('click_history=' + encodeURIComponent(clickHistory));
			}

			if (queryParts.length)
			{
				requestUrl += '?' + queryParts.join('&');
			}

			return {
				url: requestUrl,
				method: isUpdate ? 'PUT' : 'POST'
			};
		}

		return {
			navigation: {
				buildEditUrl: buildEditUrl
			},
			api: {
				buildSaveRequest: buildSaveRequest,
				refreshClickHistoryToken: function ()
				{
					return refreshClickHistoryToken(tokenStoreKey);
				}
			}
		};
	}

	function createEntityClients(form, deps)
	{
		var parsed = deps.parseURL(form.action);
		var query = parsed.searchObject || {};
		var tokenStoreKey = getStoreKey('entity', form);

		function buildEditUrl(type, entityId, catId, id)
		{
			return global.phpGWLink('index.php', {
				menuaction: 'property.uientity.edit',
				type: type,
				entity_id: entityId,
				cat_id: catId,
				id: id
			});
		}

		function buildIndexUrl(type, entityId, catId)
		{
			return global.phpGWLink('index.php', {
				menuaction: 'property.uientity.index',
				entity_id: entityId,
				cat_id: catId,
				type: type
			});
		}

		function buildSaveRequest(submitterName)
		{
			var type = query.type || '';
			var entityId = query.entity_id || '';
			var catId = query.cat_id || '';

			if (!type || !entityId || !catId)
			{
				var pathMatch = parsed.pathname.match(/\/property\/entity\/([^\/]+)\/(\d+)\/(\d+)/);
				if (pathMatch)
				{
					if (!type) { type = decodeURIComponent(pathMatch[1]); }
					if (!entityId) { entityId = pathMatch[2]; }
					if (!catId) { catId = pathMatch[3]; }
				}
			}

			if (!type) { type = global.$ ? global.$('#field_type').val() || '' : ''; }
			if (!catId || catId === '0')
			{
				catId = global.$ ? global.$('#cat_id').val() || '' : '';
			}

			var rawId = (query.id || global.item_id || '').toString();
			var id = parseInt(rawId, 10);
			var bypass = query.bypass;

			if (!type || !entityId || !catId)
			{
				return null;
			}

			var isCreate = !id;
			var url = '/property/entity/' + encodeURIComponent(type) + '/' + entityId + '/' + catId;
			if (!isCreate)
			{
				url += '/' + id;
			}

			var queryParts = [];
			if (typeof bypass !== 'undefined' && bypass !== null && bypass !== '')
			{
				queryParts.push('bypass=' + encodeURIComponent(bypass));
			}

			var clickHistory = getClickHistoryToken(tokenStoreKey, query, deps);
			if (clickHistory)
			{
				queryParts.push('click_history=' + encodeURIComponent(clickHistory));
			}
			if (queryParts.length)
			{
				url += '?' + queryParts.join('&');
			}

			return {
				url: url,
				method: isCreate ? 'POST' : 'PUT',
				isCreate: isCreate,
				type: type,
				entityId: entityId,
				catId: catId
			};
		}

		return {
			navigation: {
				buildEditUrl: buildEditUrl,
				buildIndexUrl: buildIndexUrl
			},
			api: {
				buildSaveRequest: buildSaveRequest,
				refreshClickHistoryToken: function ()
				{
					return refreshClickHistoryToken(tokenStoreKey);
				}
			}
		};
	}

	function createProjectClients(form, deps)
	{
		var parsed = deps.parseURL(form.action);
		var query = parsed.searchObject || {};
		var tokenStoreKey = getStoreKey('project', form);

		function buildEditUrl(projectId)
		{
			return global.phpGWLink('index.php', {
				menuaction: 'property.uiproject.edit',
				id: projectId
			});
		}

		function buildSaveRequest(currentProjectId)
		{
			var projectId = parseInt(currentProjectId, 10);
			var isUpdate = !isNaN(projectId) && projectId > 0;
			var basePath = isUpdate
				? '/property/project/' + encodeURIComponent(projectId)
				: '/property/project/create';
			var queryParts = [];
			var clickHistory = getClickHistoryToken(tokenStoreKey, query, deps);
			if (clickHistory)
			{
				queryParts.push('click_history=' + encodeURIComponent(clickHistory));
			}
			var requestUrl = basePath;
			if (queryParts.length)
			{
				requestUrl += '?' + queryParts.join('&');
			}

			return {
				url: requestUrl,
				method: isUpdate ? 'PUT' : 'POST'
			};
		}

		return {
			navigation: {
				buildEditUrl: buildEditUrl
			},
			api: {
				buildSaveRequest: buildSaveRequest,
				refreshClickHistoryToken: function ()
				{
					return refreshClickHistoryToken(tokenStoreKey);
				}
			}
		};
	}

	global.PorticoBoundaryClients = global.PorticoBoundaryClients || {
		createLocationClients: createLocationClients,
		createEntityClients: createEntityClients,
		createProjectClients: createProjectClients
	};
})(window);
