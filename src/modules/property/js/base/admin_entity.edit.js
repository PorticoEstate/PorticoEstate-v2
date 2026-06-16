this.onActionsClick = function ()
{
	// check for items in localstorage with name beginning with menu_tree_ and delete it.
	if (window.localStorage)
	{
		for (var i = 0; i < localStorage.length; i++)
		{
			var key = localStorage.key(i);
			if (key.indexOf('menu_tree_') === 0)
			{
				localStorage.removeItem(key);
			}
		}
	}
	document.form.submit();
};