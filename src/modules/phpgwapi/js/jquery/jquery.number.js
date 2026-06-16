/**
 * Lightweight $.number() replacement for jquery-number plugin.
 * Uses Intl.NumberFormat with nb-NO locale (space thousands, comma decimal).
 *
 * Original signature: $.number(number, decimals, dec_point, thousands_sep)
 * The dec_point and thousands_sep params are kept for backwards compatibility
 * but are now ignored — formatting always uses nb-NO locale conventions.
 */
(function ($) {
	'use strict';
	$.number = function (number, decimals, dec_point, thousands_sep) {
		var n = !isFinite(+number) ? 0 : +number;
		return new Intl.NumberFormat('nb-NO', {
			minimumFractionDigits: decimals || 0,
			maximumFractionDigits: decimals || 0,
			useGrouping: true
		}).format(n);
	};
})(jQuery);
