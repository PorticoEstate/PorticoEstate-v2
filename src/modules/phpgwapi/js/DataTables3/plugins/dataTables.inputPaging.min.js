(function (factory)
{
	let $, dataTable;

	if (typeof define === 'function' && define.amd)
	{
		define(['jquery', 'datatables.net'], function (jQuery)
		{
			return factory(jQuery, window, document);
		});
	} else if (typeof exports === 'object')
	{
		$ = require('jquery');
		dataTable = function (root, $)
		{
			if (!$.fn.dataTable)
			{
				require('datatables.net')(root, $);
			}
		};

		if (typeof window === 'undefined')
		{
			module.exports = function (root, $)
			{
				root = root || window;
				$ = $ || require('jquery')(root);
				dataTable(root, $);
				return factory($, 0, root.document);
			};
		} else
		{
			dataTable(window, $);
			module.exports = factory($, window, window.document);
		}
	} else
	{
		factory(jQuery, window, document);
	}
})(function ($, window, document)
{
	'use strict';

	let DataTable = $.fn.dataTable;

	function toggleButtonState(element, className, state)
	{
		element.classList.toggle(className, state);
		let anchor = element.querySelector('a');
		if (anchor)
		{
			if (state)
			{
				anchor.setAttribute('disabled', 'disabled');
			} else
			{
				anchor.removeAttribute('disabled');
			}
		}
	}

	function createElement(config, text, clickHandler)
	{
		const element = document.createElement(config.tag);
		element.className = config.className;

		if (config.liner && config.liner.tag)
		{
			let linerElement = createElement(config.liner, text);
			element.appendChild(linerElement);
		} else if (text)
		{
			element.textContent = text;
		}

		if (clickHandler)
		{
			element.addEventListener('click', clickHandler);
		}

		return element;
	}

	DataTable.feature.register('inputPaging', function (settings, options)
	{
		let api = new DataTable.Api(settings);
		let classes = getFrameworkClasses(api);

		let config = Object.assign({
			firstLast: true,
			previousNext: true,
			pageOf: true
		}, options);

		let wrapper = createElement(classes.wrapper);
		let firstButton = createElement(classes.item, api.i18n('oPaginate.sFirst', '«'),
			() => api.page('first').draw(false));
		let previousButton = createElement(classes.item, api.i18n('oPaginate.sPrevious', '‹'),
			() => api.page('previous').draw(false));
		let nextButton = createElement(classes.item, api.i18n('oPaginate.sNext', '›'),
			() => api.page('next').draw(false));
		let lastButton = createElement(classes.item, api.i18n('oPaginate.sLast', '»'),
			() => api.page('last').draw(false));

		let inputContainer = createElement(classes.inputItem);
		let input = createElement(classes.input);
		let pageInfo = createElement({ tag: 'span', className: '' });

		input.setAttribute('type', 'text');
		input.setAttribute('inputmode', 'numeric');
		input.setAttribute('pattern', '[0-9]*');

		if (config.firstLast)
		{
			wrapper.appendChild(firstButton);
		}
		if (config.previousNext)
		{
			wrapper.appendChild(previousButton);
		}

		wrapper.appendChild(inputContainer);

		if (config.previousNext)
		{
			wrapper.appendChild(nextButton);
		}
		if (config.firstLast)
		{
			wrapper.appendChild(lastButton);
		}

		inputContainer.appendChild(input);
		if (config.pageOf)
		{
			inputContainer.appendChild(pageInfo);
		}

		input.addEventListener('keypress', function (e)
		{
			if (e.charCode < 48 || e.charCode > 57)
			{
				e.preventDefault();
			}
		});

		input.addEventListener('input', function ()
		{
			if (input.value)
			{
				api.page(input.value - 1).draw(false);
			}
			input.style.width = (input.value.length + 2) + 'ch';
		});

		// Add keyboard navigation
		document.addEventListener('keydown', function (e)
		{
			// Only handle keyboard events when input is focused
			if (document.activeElement !== input)
			{
				return;
			}

			switch (e.key)
			{
				case 'ArrowLeft':
					e.preventDefault();
					if (!previousButton.classList.contains(classes.item.disabled))
					{
						api.page('previous').draw(false);
					}
					break;
				case 'ArrowRight':
					e.preventDefault();
					if (!nextButton.classList.contains(classes.item.disabled))
					{
						api.page('next').draw(false);
					}
					break;
				case 'Home':
					e.preventDefault();
					if (!firstButton.classList.contains(classes.item.disabled))
					{
						api.page('first').draw(false);
					}
					break;
				case 'End':
					e.preventDefault();
					if (!lastButton.classList.contains(classes.item.disabled))
					{
						api.page('last').draw(false);
					}
					break;
			}
		});

		// Add focus styles
		input.addEventListener('focus', function ()
		{
			wrapper.classList.add('dt-paging-focused');
		});

		input.addEventListener('blur', function ()
		{
			wrapper.classList.remove('dt-paging-focused');
		});

		api.on('draw', () =>
		{
			let info = api.page.info();
			toggleButtonState(firstButton, classes.item.disabled, info.page === 0);
			toggleButtonState(previousButton, classes.item.disabled, info.page === 0);
			toggleButtonState(nextButton, classes.item.disabled, info.page === info.pages - 1);
			toggleButtonState(lastButton, classes.item.disabled, info.page === info.pages - 1);

			if (input.value !== info.page + 1)
			{
				input.value = info.page + 1;
			}
			pageInfo.textContent = ' / ' + info.pages;
		});

		return wrapper;
	});

	function getFrameworkClasses(api)
	{
		let container = api.table().container();
		let classList = container.classList;

		if (classList.contains('dt-bootstrap5') ||
			classList.contains('dt-bootstrap4') ||
			classList.contains('dt-bootstrap'))
		{
			return {
				wrapper: { tag: 'ul', className: 'dt-inputpaging pagination' },
				item: {
					tag: 'li',
					className: 'page-item',
					disabled: 'disabled',
					liner: { tag: 'a', className: 'page-link' }
				},
				inputItem: { tag: 'li', className: 'page-item dt-paging-input' },
				input: { tag: 'input', className: '' }
			};
		}

		if (classList.contains('dt-bulma'))
		{
			return {
				wrapper: { tag: 'ul', className: 'dt-inputpaging pagination-list' },
				item: {
					tag: 'li',
					className: '',
					disabled: 'disabled',
					liner: { tag: 'a', className: 'pagination-link' }
				},
				inputItem: { tag: 'li', className: 'dt-paging-input' },
				input: { tag: 'input', className: '' }
			};
		}

		if (classList.contains('dt-foundation'))
		{
			return {
				wrapper: { tag: 'ul', className: 'dt-inputpaging pagination' },
				item: {
					tag: 'li',
					className: '',
					disabled: 'disabled',
					liner: { tag: 'a', className: '' }
				},
				inputItem: { tag: 'li', className: 'dt-paging-input' },
				input: { tag: 'input', className: '' }
			};
		}

		if (classList.contains('dt-semanticUI'))
		{
			return {
				wrapper: { tag: 'div', className: 'dt-inputpaging ui unstackable pagination menu' },
				item: { tag: 'a', className: 'page-link item', disabled: 'disabled' },
				inputItem: { tag: 'div', className: 'dt-paging-input' },
				input: { tag: 'input', className: 'ui input' }
			};
		}

		return {
			wrapper: { tag: 'div', className: 'dt-inputpaging dt-paging' },
			item: { tag: 'button', className: 'dt-paging-button', disabled: 'disabled' },
			inputItem: {
				tag: 'div',
				className: 'dt-paging-input',
				liner: { tag: '', className: '' }
			},
			input: { tag: 'input', className: '' }
		};
	}

	return DataTable;
});