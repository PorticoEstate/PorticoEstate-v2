(function ()
{
	'use strict';

	var scroller = document.querySelector('.calendar-planner__table');
	if (!scroller) return;

	var dragging = false;
	var moved = false;
	var startX = 0;
	var startScrollLeft = 0;

	scroller.addEventListener('pointerdown', function (event)
	{
		if (event.pointerType === 'mouse' && event.button !== 0) return;
		dragging = true;
		moved = false;
		scroller.classList.add('calendar-planner__table--dragging');
		startX = event.clientX;
		startScrollLeft = scroller.scrollLeft;
		scroller.setPointerCapture(event.pointerId);
	});

	scroller.addEventListener('pointermove', function (event)
	{
		if (!dragging) return;
		var distance = event.clientX - startX;
		if (Math.abs(distance) > 4) moved = true;
		if (moved)
		{
			scroller.scrollLeft = startScrollLeft - distance;
			event.preventDefault();
		}
	});

	function stopDragging(event)
	{
		if (!dragging) return;
		dragging = false;
		scroller.classList.remove('calendar-planner__table--dragging');
		if (scroller.hasPointerCapture(event.pointerId))
		{
			scroller.releasePointerCapture(event.pointerId);
		}
		if (moved)
		{
			scroller.classList.add('calendar-planner__table--dragged');
			window.setTimeout(function ()
			{
				scroller.classList.remove('calendar-planner__table--dragged');
			}, 0);
		}
	}

	scroller.addEventListener('pointerup', stopDragging);
	scroller.addEventListener('pointercancel', stopDragging);
	scroller.addEventListener('click', function (event)
	{
		if (scroller.classList.contains('calendar-planner__table--dragged'))
		{
			event.preventDefault();
			event.stopPropagation();
		}
	}, true);
})();
