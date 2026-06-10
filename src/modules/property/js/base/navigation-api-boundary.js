(function (global)
{
	'use strict';

	function createLocationClients(form, deps)
	{
		var parsed = deps.parseURL(form.action);
		var query = parsed.searchObject || {};

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
			var clickHistory = query.click_history || '';
			var queryParts = [];
			var originalLocationCode = (query.location_code || deps.getLocationFieldValue(form, 'input[name="location_code"]') || '').trim();
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
				buildSaveRequest: buildSaveRequest
			}
		};
	}

	function createEntityClients(form, deps)
	{
		var parsed = deps.parseURL(form.action);
		var query = parsed.searchObject || {};

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
			var isApply = (submitterName === 'values[apply]');
			var clickHistory = isApply ? '' : (query.click_history || '');

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

			if (!isApply && !clickHistory && typeof global.strBaseURL !== 'undefined' && global.strBaseURL)
			{
				var baseQuery = deps.parseURL(global.strBaseURL).searchObject || {};
				clickHistory = baseQuery.click_history || '';
			}

			var queryParts = [];
			if (typeof bypass !== 'undefined' && bypass !== null && bypass !== '')
			{
				queryParts.push('bypass=' + encodeURIComponent(bypass));
			}
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
				buildSaveRequest: buildSaveRequest
			}
		};
	}

	function createProjectClients(form, deps)
	{
		var parsed = deps.parseURL(form.action);
		var query = parsed.searchObject || {};

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

			var clickHistory = query.click_history || '';
			if (!clickHistory && typeof global.strBaseURL !== 'undefined' && global.strBaseURL)
			{
				var baseQuery = deps.parseURL(global.strBaseURL).searchObject || {};
				clickHistory = baseQuery.click_history || '';
			}

			var queryParts = [];
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
				buildSaveRequest: buildSaveRequest
			}
		};
	}

	global.PorticoBoundaryClients = global.PorticoBoundaryClients || {
		createLocationClients: createLocationClients,
		createEntityClients: createEntityClients,
		createProjectClients: createProjectClients
	};
})(window);
