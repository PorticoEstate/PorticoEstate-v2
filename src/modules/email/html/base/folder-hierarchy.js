window.emailFolderHierarchy = function (items, separator)
{
	const key = (account, path) => JSON.stringify([account, path]);
	const nodes = new Map(items.map(folder => [key(folder.account, folder.longName), { folder, children: [], parent: null }]));
	const roots = [];
	for (const node of nodes.values())
	{
		let path = node.folder.longName;
		while (separator && path.includes(separator))
		{
			path = path.slice(0, path.lastIndexOf(separator));
			const parent = nodes.get(key(node.folder.account, path));
			if (parent)
			{
				node.parent = parent;
				parent.children.push(node);
				break;
			}
		}
		if (!node.parent) roots.push(node);
	}
	const ordered = [];
	function append(branch, depth)
	{
		branch.sort((first, second) => first.folder.longName.localeCompare(second.folder.longName, undefined, { numeric: true }));
		branch.forEach(node =>
		{
			const label = node.parent ? node.folder.longName.slice(node.parent.folder.longName.length + separator.length) : (node.folder.name || node.folder.longName);
			ordered.push({ ...node.folder, label, depth });
			append(node.children, depth + 1);
		});
	}
	append(roots, 0);
	return ordered;
};