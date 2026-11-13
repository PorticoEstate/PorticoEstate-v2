/**
 * porticoMultiselect - shared Select2 wrapper for <select multiple> controls.
 * Replaces the former bootstrap-multiselect plugin, and works without Bootstrap.
 *
 * Usage:
 *   $(selector).porticoMultiselect({...});  // initialise (or refresh if already initialised)
 *   $(selector).porticoMultiselect('refresh'); // re-read options/selection after DOM changes
 *   $(selector).porticoMultiselect('clear');   // deselect everything and trigger change
 *   $(selector).porticoMultiselect('destroy');
 *
 * Portico options (everything else is passed straight to Select2):
 *   selectAll     - add "select all"/"clear all" buttons to the dropdown (default true)
 *   selectAllText - label for the select-all button
 *   clearAllText  - label for the clear-all button
 */
(function ($)
{
	'use strict';

	if (!$ || !$.fn || !$.fn.select2)
	{
		return;
	}

	var portico_keys = ['selectAll', 'selectAllText', 'clearAllText'];

	function documentLanguage()
	{
		var lang = (document.documentElement.getAttribute('lang') || '').toLowerCase();
		return lang || 'no';
	}

	function isNorwegian(lang)
	{
		return /^(no|nb|nn)\b/.test(lang);
	}

	function resolveTheme()
	{
		return $('link[href*="select2-bootstrap-5-theme"]').length ? 'bootstrap-5' : 'default';
	}

	function resolvePlaceholder($select)
	{
		var id = $select.attr('id');
		var text = id ? $('label[for="' + id + '"]').first().text().trim() : '';
		if (!text)
		{
			var title = ($select.attr('title') || '').trim();
			// Titles prefixed with "!" are datatable filter sentinels, not human-readable labels.
			text = title.charAt(0) === '!' ? '' : title;
		}
		return text;
	}

	function currentSearchTerm(instance)
	{
		var $search = instance.$dropdown.find('.select2-search__field');
		if (!$search.length)
		{
			$search = instance.$container.find('.select2-search__field');
		}
		return $search.val() || '';
	}

	function rerenderResults(instance)
	{
		if (instance.isOpen())
		{
			instance.trigger('query', {term: currentSearchTerm(instance)});
		}
	}

	function addSelectAllToolbar($select, settings)
	{
		$select.on('select2:open.porticoMultiselect', function ()
		{
			var instance = $select.data('select2');
			var $dropdown = instance && instance.$dropdown ? instance.$dropdown.find('.select2-dropdown') : $();
			if (!$dropdown.length || $dropdown.children('.portico-multiselect-toolbar').length)
			{
				return;
			}

			var buttonClass = 'pure-button pure-button-secondary btn btn-sm btn-outline-secondary';
			var $toolbar = $('<div class="portico-multiselect-toolbar"></div>');
			var $all = $('<button type="button"></button>').addClass(buttonClass).text(settings.selectAllText);
			var $none = $('<button type="button"></button>').addClass(buttonClass).text(settings.clearAllText);

			// Keep focus in the search field so the dropdown stays open.
			$toolbar.on('mousedown', 'button', function (e)
			{
				e.preventDefault();
				e.stopPropagation();
			});

			$all.on('click', function ()
			{
				$select.find('option:not(:disabled)').prop('selected', true);
				$select.trigger('change');
				rerenderResults(instance);
			});

			$none.on('click', function ()
			{
				$select.val(null).trigger('change');
				rerenderResults(instance);
			});

			$toolbar.append($all, $none).prependTo($dropdown);
		});
	}

	function initialise($select, options)
	{
		if ($select.hasClass('select2-hidden-accessible'))
		{
			$select.trigger('change.select2');
			return;
		}

		var lang = documentLanguage();
		var norwegian = isNorwegian(lang);
		var settings = $.extend({
			selectAll: true,
			selectAllText: norwegian ? 'Velg alle' : 'Select all',
			clearAllText: norwegian ? 'Fjern alle' : 'Clear all'
		}, options);

		var config = {
			theme: resolveTheme(),
			language: lang,
			width: '100%',
			closeOnSelect: false,
			allowClear: true,
			placeholder: resolvePlaceholder($select)
		};

		var $modal = $select.closest('.modal, dialog');
		if ($modal.length)
		{
			config.dropdownParent = $modal;
		}

		$.each(options || {}, function (key, value)
		{
			if (portico_keys.indexOf(key) === -1)
			{
				config[key] = value;
			}
		});

		$select.select2(config);

		if (settings.selectAll && $select.prop('multiple'))
		{
			addSelectAllToolbar($select, settings);
		}
	}

	$.fn.porticoMultiselect = function (options)
	{
		if (typeof options === 'string')
		{
			var method = options;
			return this.each(function ()
			{
				var $select = $(this);
				var initialised = $select.hasClass('select2-hidden-accessible');

				switch (method)
				{
					case 'refresh':
						if (initialised)
						{
							$select.trigger('change.select2');
						}
						break;
					case 'clear':
						$select.find('option').prop('selected', false);
						$select.trigger('change');
						break;
					case 'destroy':
						if (initialised)
						{
							$select.off('.porticoMultiselect').select2('destroy');
						}
						break;
				}
			});
		}

		return this.each(function ()
		{
			initialise($(this), options);
		});
	};
})(window.jQuery);
