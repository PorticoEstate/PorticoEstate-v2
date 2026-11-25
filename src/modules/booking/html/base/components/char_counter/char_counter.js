/**
 * A character counter under a text field, in the shape of Digdir's Field.Counter (the React one can't be used on
 * these vanilla pages): «N tegn igjen» while within the limit, a danger validation message «N tegn for mye» over
 * it, the same text in a polite live region for screen readers, and a «Maks N tegn» hint the field is described by.
 * It never truncates (no maxlength): what the page blocks is the save.
 *
 * Counts Unicode code points (Array.from), the unit the server counts in (mb_strlen), so «æøå» counts 3 and an
 * emoji 1. Digdir's own counter uses String.length, which counts an emoji as 2.
 *
 *   var counter = CharCounter.attach(textarea, {limit: 70, under: '%1 tegn igjen', over: '%1 tegn for mye',
 *                                                hint: 'Maks %1 tegn.', value: function () { ... }});
 *   counter.exceeded()  true while the counted text is over the limit
 *
 * `value` is optional: what the page will send, when that is not the raw field value (a form that trims).
 */
window.CharCounter = window.CharCounter || (function ()
{
	'use strict';

	var sequence = 0;

	function count(text)
	{
		return Array.from(text == null ? '' : String(text)).length;
	}

	function fill(text, number)
	{
		return String(text).replace('%1', String(number));
	}

	function attach(field, options)
	{
		var limit = options.limit;
		var valueOf = options.value || function () { return field.value; };
		var id = 'char-counter-' + (++sequence);

		var hint = document.createElement('div');
		hint.className = 'ds-sr-only';
		hint.id = id + '-hint';
		hint.textContent = fill(options.hint, limit);

		var visible = document.createElement('p');
		visible.setAttribute('aria-hidden', 'true');
		visible.dataset.size = 'sm';

		var live = document.createElement('div');
		live.className = 'ds-sr-only';
		live.setAttribute('aria-live', 'polite');

		field.insertAdjacentElement('afterend', live);
		field.insertAdjacentElement('afterend', hint);
		field.insertAdjacentElement('afterend', visible);

		var describedBy = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
		describedBy.push(hint.id);
		field.setAttribute('aria-describedby', describedBy.join(' '));

		var liveTimer = null;

		function render()
		{
			var remainder = limit - count(valueOf());
			var over = remainder < 0;
			visible.className = over ? 'ds-validation-message' : 'ds-paragraph';
			if (over)
			{
				visible.dataset.color = 'danger';
			} else
			{
				delete visible.dataset.color;
			}
			visible.textContent = fill(over ? options.over : options.under, Math.abs(remainder));
			// Read out once typing pauses, as Digdir's counter does, not on every keystroke
			clearTimeout(liveTimer);
			liveTimer = setTimeout(function () { live.textContent = visible.textContent; }, 1200);
		}

		field.addEventListener('input', render);
		render();

		return {
			exceeded: function () { return count(valueOf()) > limit; }
		};
	}

	return {attach: attach, count: count};
})();
