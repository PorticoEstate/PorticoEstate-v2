/**
 * Language Selector Component
 * Self-contained: fetches data from REST API, renders UI, handles language switching.
 * Uses native Popover API with Digdir ds-popover/ds-dropdown classes.
 */
(function () {
	'use strict';

	class LanguageSelector {
		constructor(element) {
			this.element = element;
			this.data = null;
			this.init();
		}

		async init() {
			try {
				this.data = await this.fetchLanguages();
				this.render();
				this.attachEventListeners();
			} catch (err) {
				console.error('LanguageSelector: failed to initialize', err);
			}
		}

		async fetchLanguages() {
			const res = await fetch('/api/languages', { credentials: 'same-origin' });
			if (!res.ok) {
				throw new Error('Failed to fetch languages: ' + res.status);
			}
			return res.json();
		}

		render() {
			const { languages, translations } = this.data;
			const selected = languages.find(l => l.is_selected) || languages[0];
			const popoverId = 'languageSelectorPopover';

			const listItems = languages.map(lang => {
				const checked = lang.is_selected ? 'checked' : '';
				return `<li>
					<label class="app-language-selector__item">
						<input type="radio" name="select_language" value="${lang.code}"
							data-flag="${lang.flag_class}" ${checked} />
						<i class="fi ${lang.flag_class}"></i>
						<span>${lang.name}</span>
					</label>
				</li>`;
			}).join('');

			this.element.innerHTML = `
				<button class="app-dropdown__trigger" type="button"
					popovertarget="${popoverId}"
					title="${translations.choose_language}"
					aria-expanded="false">
					<i class="fi ${selected.flag_class}"></i>
					<span class="u-hidden-mobile">${selected.name}</span>
				</button>
				<div class="ds-popover ds-dropdown app-language-selector__dropdown"
					id="${popoverId}"
					popover="auto"
					data-variant="default">
					<h6>${translations.choose_language}</h6>
					<p class="app-language-selector__subtitle">${translations.choose_language_subtitle}</p>
					<ul>
						${listItems}
					</ul>
				</div>`;
		}

		attachEventListeners() {
			const popover = this.element.querySelector('[popover]');
			const trigger = this.element.querySelector('.app-dropdown__trigger');
			if (!popover || !trigger) return;

			// Sync aria-expanded with popover state
			popover.addEventListener('toggle', (e) => {
				trigger.setAttribute('aria-expanded', e.newState === 'open');
			});

			// Language change
			popover.addEventListener('change', (e) => {
				if (e.target.name !== 'select_language') return;
				const code = e.target.value;
				const flagClass = e.target.dataset.flag;
				const label = e.target.closest('label');
				const name = label ? label.querySelector('span').textContent.trim() : code;
				this.changeLanguage(code, flagClass, name);
			});
		}

		async changeLanguage(code, flagClass, name) {
			// Instant visual feedback on trigger button
			const trigger = this.element.querySelector('.app-dropdown__trigger');
			if (trigger) {
				const flag = trigger.querySelector('.fi');
				if (flag) {
					flag.className = flag.className.replace(/fi-\w+/g, '');
					flag.classList.add(flagClass);
				}
				const span = trigger.querySelector('.u-hidden-mobile');
				if (span) {
					span.textContent = name;
				}
			}

			try {
				const res = await fetch('/api/set-language/' + encodeURIComponent(code), {
					credentials: 'same-origin'
				});
				const result = await res.json();

				if (result.success) {
					Object.keys(localStorage).forEach(key => {
						if (key.startsWith('menu_tree_') || key.includes('lang_')) {
							localStorage.removeItem(key);
						}
					});
					window.location.reload();
				} else {
					console.error('Failed to set language:', result.error);
				}
			} catch (err) {
				console.error('LanguageSelector: changeLanguage failed', err);
			}
		}
	}

	function initAll() {
		document.querySelectorAll('[data-component="language-selector"]').forEach(el => {
			new LanguageSelector(el);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
