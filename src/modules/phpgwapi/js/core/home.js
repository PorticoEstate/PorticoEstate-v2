document.addEventListener('DOMContentLoaded', function ()
{
	const content = document.getElementById('home-hook-content');
	if (!content)
	{
		return;
	}

	function wrapTables()
	{
		content.querySelectorAll('.home-table-scroll:empty').forEach(function (wrapper)
		{
			wrapper.remove();
		});

		content.querySelectorAll('table').forEach(function (table)
		{
			if (table.closest('.home-table-scroll'))
			{
				return;
			}

			const wrapper = document.createElement('div');
			wrapper.className = 'home-table-scroll';
			wrapper.tabIndex = 0;
			table.before(wrapper);
			wrapper.appendChild(table);
		});
	}

	wrapTables();
	new MutationObserver(wrapTables).observe(content, {childList: true, subtree: true});
});
