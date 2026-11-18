$(function ()
{
	const menu = $('#menu');
	if (!menu.length) return;

	menu.mmenu({
		offCanvas: false,
		extensions: ['effect-slide-menu'],
		searchfield: true,
		counters: true,
		navbars: [
			{ position: 'top', content: ['searchfield'] },
			{ position: 'top', content: ['prev', 'title'] }
		]
	});

	menu.on('click', 'input[name="update_bookmark_menu"]', function (event)
	{
		event.preventDefault();
		event.stopPropagation();
		const anchor = $(this).closest('a');
		const requestUrl = phpGWLink('index.php', { menuaction: 'phpgwapi.menu.update_bookmark_menu' }, true);

		$.ajax({
			type: 'POST',
			url: requestUrl,
			dataType: 'json',
			data: {
				bookmark_candidate: this.value,
				text: anchor.text().trim(),
				href: anchor.attr('href'),
				icon: anchor.attr('data-icon')
			},
			success: function (data)
			{
				alert(data.status);
				location.reload();
			},
			error: function ()
			{
				alert('Could not update the bookmark.');
			}
		});
	});
});
