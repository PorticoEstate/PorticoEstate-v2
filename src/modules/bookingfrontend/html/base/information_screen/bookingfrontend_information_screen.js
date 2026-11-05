(function ()
{
	const picker = document.getElementById('information-screen-picker');
	if (!picker)
	{
		return;
	}

	const buildingSearch = document.getElementById('information-screen-building-search');
	const buildingResults = document.getElementById('information-screen-building-results');
	const resourcePanel = document.getElementById('information-screen-resource-panel');
	const buildingName = document.getElementById('information-screen-building-name');
	const resourceSearch = document.getElementById('information-screen-resource-search');
	const resourceList = document.getElementById('information-screen-resource-list');
	const status = document.getElementById('information-screen-picker-status');
	const linkPanel = document.getElementById('information-screen-link-panel');
	const linkInput = document.getElementById('information-screen-link');
	const openLink = document.getElementById('information-screen-open-link');
	const copyLink = document.getElementById('information-screen-copy-link');
	const days = document.getElementById('information-screen-days');
	const selectionPanel = document.getElementById('information-screen-selection-panel');
	const selectionList = document.getElementById('information-screen-selection-list');
	const selectedResources = new Map();

	let selectedBuilding = null;
	let searchTimeout = null;
	let buildingSearchSequence = 0;
	let resourceLoadSequence = 0;

	function setStatus(message)
	{
		status.textContent = message;
	}

	function clearGeneratedLink()
	{
		linkPanel.hidden = true;
		linkInput.value = '';
		openLink.hidden = true;
		openLink.removeAttribute('href');
		copyLink.disabled = true;
	}

	function updateGeneratedLink()
	{
		if (selectedResources.size === 0)
		{
			clearGeneratedLink();
			return;
		}

		const url = new URL(picker.dataset.informationScreenUrl, window.location.href);
		const domain = url.searchParams.get('domain');
		url.search = '';
		if (domain)
		{
			url.searchParams.set('domain', domain);
		}
		url.searchParams.set('menuaction', 'bookingfrontend.uibuilding.information_screen');
		const buildingIds = [...new Set([...selectedResources.values()].map((resource) => resource.building.id))];
		if (buildingIds.length === 1)
		{
			url.searchParams.set('id', buildingIds[0]);
		}
		else
		{
			buildingIds.forEach((id) => url.searchParams.append('id[]', id));
		}
		url.searchParams.set('weekend', days.value);
		selectedResources.forEach((resource, id) => url.searchParams.append('resource_id[]', id));

		linkInput.value = url.toString();
		openLink.href = url.toString();
		openLink.hidden = false;
		linkPanel.hidden = false;
		copyLink.disabled = false;
	}

	function updateSelection()
	{
		selectionList.replaceChildren();
		selectedResources.forEach((resource, id) =>
		{
			const item = document.createElement('li');
			const name = document.createElement('span');
			name.textContent = resource.building.name + ' / ' + resource.name;
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'information-screen-picker__button information-screen-picker__button--secondary';
			remove.textContent = picker.dataset.removeResource;
			remove.setAttribute('aria-label', picker.dataset.removeResource + ': ' + name.textContent);
			remove.addEventListener('click', () =>
			{
				const index = [...selectedResources.keys()].indexOf(id);
				selectedResources.delete(id);
				resourceList.querySelectorAll('input[type="checkbox"]').forEach((checkbox) =>
				{
					if (checkbox.value === id)
					{
						checkbox.checked = false;
					}
				});
				updateSelection();
				const buttons = selectionList.querySelectorAll('button');
				if (buttons.length)
				{
					buttons[Math.min(index, buttons.length - 1)].focus();
				}
				else
				{
					buildingSearch.focus();
				}
			});
			item.append(name, remove);
			selectionList.appendChild(item);
		});
		selectionPanel.hidden = selectedResources.size === 0;
		updateGeneratedLink();
	}

	function clearResources()
	{
		resourceList.replaceChildren();
		resourcePanel.hidden = true;
		resourceSearch.value = '';
	}

	function renderBuildingResults(buildings)
	{
		buildingResults.replaceChildren();
		buildings.forEach((building) =>
		{
			const item = document.createElement('li');
			const button = document.createElement('button');
			button.type = 'button';
			button.textContent = building.name;
			button.addEventListener('click', () =>
			{
				buildingSearch.value = building.name;
				buildingResults.hidden = true;
				resourceSearch.value = '';
				loadResources(building);
			});
			item.appendChild(button);
			buildingResults.appendChild(item);
		});

		buildingResults.hidden = buildings.length === 0;
		setStatus(buildings.length ? '' : picker.dataset.noBuildings);
	}

	async function searchBuildings(query, sequence)
	{
		setStatus(picker.dataset.searchingBuildings);
		const url = new URL(picker.dataset.buildingSearchUrl, window.location.href);
		url.searchParams.set('query', query);

		try
		{
			const response = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
			if (!response.ok)
			{
				throw new Error('Building search failed');
			}
			const data = await response.json();
			if (sequence !== buildingSearchSequence)
			{
				return;
			}
			if (!Array.isArray(data.results))
			{
				throw new Error('Invalid building search response');
			}
			renderBuildingResults(data.results);
		}
		catch (error)
		{
			if (sequence === buildingSearchSequence)
			{
				setStatus(picker.dataset.loadError);
				buildingResults.replaceChildren();
				buildingResults.hidden = true;
			}
		}
	}

	function renderResources(resources)
	{
		resourceList.replaceChildren();
		resources.forEach((resource) =>
		{
			const label = document.createElement('label');
			label.className = 'information-screen-picker__resource';
			const checkbox = document.createElement('input');
			checkbox.type = 'checkbox';
			const id = String(resource.id);
			checkbox.value = id;
			checkbox.checked = selectedResources.has(id);
			const building = selectedBuilding;
			checkbox.addEventListener('change', () =>
			{
				if (checkbox.checked)
				{
					selectedResources.set(id, {name: resource.name, building});
				}
				else
				{
					selectedResources.delete(id);
				}
				updateSelection();
			});
			const name = document.createElement('span');
			name.textContent = resource.name;
			label.append(checkbox, name);
			resourceList.appendChild(label);
		});
		resourcePanel.hidden = false;
		setStatus(resources.length ? '' : picker.dataset.noResources);
		updateGeneratedLink();
	}

	async function loadResources(building)
	{
		selectedBuilding = building;
		resourcePanel.hidden = false;
		buildingName.textContent = building.name;
		resourceList.replaceChildren();
		const sequence = ++resourceLoadSequence;
		setStatus(picker.dataset.loadingResources);

		const url = new URL(picker.dataset.resourceListUrl.replace('__BUILDING_ID__', building.id), window.location.href);
		try
		{
			const response = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
			if (!response.ok)
			{
				throw new Error('Resource loading failed');
			}
			const data = await response.json();
			if (sequence !== resourceLoadSequence)
			{
				return;
			}
			if (!Array.isArray(data.results))
			{
				throw new Error('Invalid resource response');
			}
			renderResources(data.results);
		}
		catch (error)
		{
			if (sequence === resourceLoadSequence)
			{
				setStatus(picker.dataset.loadError);
				resourceList.replaceChildren();
			}
		}
	}

	buildingSearch.addEventListener('input', () =>
	{
		clearTimeout(searchTimeout);
		buildingSearchSequence++;
		resourceLoadSequence++;
		selectedBuilding = null;
		clearResources();
		buildingResults.replaceChildren();
		buildingResults.hidden = true;

		const query = buildingSearch.value.trim();
		if (query.length < 2)
		{
			buildingResults.hidden = true;
			setStatus(picker.dataset.selectBuilding);
			return;
		}

		const sequence = buildingSearchSequence;
		searchTimeout = window.setTimeout(() => searchBuildings(query, sequence), 250);
	});

	buildingSearch.addEventListener('keydown', (event) =>
	{
		if (event.key === 'Escape')
		{
			buildingResults.hidden = true;
		}
		else if (event.key === 'ArrowDown' && !buildingResults.hidden)
		{
			event.preventDefault();
			buildingResults.querySelector('button')?.focus();
		}
	});

	buildingResults.addEventListener('keydown', (event) =>
	{
		if (event.key === 'Escape')
		{
			buildingResults.hidden = true;
			buildingSearch.focus();
		}
	});

	resourceSearch.addEventListener('input', () =>
	{
		const query = resourceSearch.value.trim().toLocaleLowerCase();
		resourceList.querySelectorAll('.information-screen-picker__resource').forEach((label) =>
		{
			label.hidden = !label.textContent.toLocaleLowerCase().includes(query);
		});
	});

	days.addEventListener('change', updateGeneratedLink);

	copyLink.addEventListener('click', async () =>
	{
		try
		{
			await navigator.clipboard.writeText(linkInput.value);
			setStatus(picker.dataset.linkCopied);
		}
		catch (error)
		{
			linkInput.focus();
			linkInput.select();
			setStatus(picker.dataset.copyError);
		}
	});
})();
