/**
 * MultiLanguageText — Reusable multi-language text input component.
 *
 * Provides a text input (or textarea) with language tabs. Each tab holds a
 * separate value keyed by language code. A status indicator shows whether all
 * languages are filled.
 *
 * Usage:
 *   var field = new MultiLanguageText(containerEl, {
 *       languages:   [{code:'no', label:'Bokm\u00e5l'}, {code:'en', label:'English'}, {code:'nn', label:'Nynorsk'}],
 *       value:       { no: 'Hei', en: 'Hello', nn: '' },
 *       inputType:   'text',          // 'text' | 'textarea'
 *       placeholder: 'Enter name...', // optional, per-language: { no: '...', en: '...' }
 *       required:    false,           // if true, at least the fallback language must be filled
 *       onChange:    function(values) { ... }
 *   });
 *
 * The container element should be a <div class="mlt"> with the component
 * markup rendered by multi_language_text.twig, OR the component can build
 * the DOM itself when instantiated on an empty container.
 *
 * Fallback logic:
 *   - 'nn' falls back to 'no' and vice versa (Bokm\u00e5l/Nynorsk)
 *   - All other languages stand alone
 */
class MultiLanguageText {
	/**
	 * Fetch content languages from the platform API.
	 * Caches the result so only one request is made per page load.
	 * Returns a promise resolving to [{code, label}, ...].
	 */
	static _cachedLanguages = null;
	static fetchLanguages() {
		if (MultiLanguageText._cachedLanguages) {
			return Promise.resolve(MultiLanguageText._cachedLanguages);
		}
		return fetch('/api/languages', { credentials: 'same-origin' })
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (data) {
				var langs = (data.languages || []).map(function (l) {
					return { code: l.code, label: l.name };
				});
				MultiLanguageText._cachedLanguages = langs;
				return langs;
			});
	}

	constructor(container, options) {
		this.container = container;
		this.languages = options.languages || [];
		this.label = options.label || '';
		this.inputType = options.inputType || 'text';
		this.placeholder = options.placeholder || '';
		this.required = options.required || false;
		this.onChange = options.onChange || function () {};
		this.disabled = options.disabled || false;
		this.fallbackHintPrefix = options.fallbackHintPrefix || 'Uses';

		// Fallback pairs: nn <-> no
		this._fallbackPairs = { nn: 'no', no: 'nn' };

		// Internal state: { code: value }
		this._values = {};
		for (var i = 0; i < this.languages.length; i++) {
			var lang = this.languages[i];
			this._values[lang.code] = (options.value && options.value[lang.code]) || '';
		}

		this._activeLang = this.languages.length > 0 ? this.languages[0].code : null;
		this._built = false;

		// Build or bind DOM
		if (container.querySelector('.mlt__tabs')) {
			this._bindExisting();
		} else {
			this._buildDom();
		}

		this._updateStatus();
		this._showTab(this._activeLang);
	}

	// ── Public API ─────────────────────────────────────────────

	getValue() {
		return Object.assign({}, this._values);
	}

	setValue(values) {
		for (var i = 0; i < this.languages.length; i++) {
			var code = this.languages[i].code;
			this._values[code] = (values && values[code]) || '';
			var input = this.container.querySelector('[data-mlt-input="' + code + '"]');
			if (input) input.value = this._values[code];
		}
		this._updateStatus();
	}

	setDisabled(disabled) {
		this.disabled = disabled;
		var inputs = this.container.querySelectorAll('[data-mlt-input]');
		for (var i = 0; i < inputs.length; i++) {
			inputs[i].disabled = disabled;
		}
	}

	getEffectiveValue(langCode) {
		var val = this._values[langCode];
		if (val && val.trim()) return val;

		// Fallback: nn <-> no
		var fb = this._fallbackPairs[langCode];
		if (fb && this._values[fb] && this._values[fb].trim()) {
			return this._values[fb];
		}
		return '';
	}

	isComplete() {
		for (var i = 0; i < this.languages.length; i++) {
			var code = this.languages[i].code;
			if (!this._values[code] || !this._values[code].trim()) {
				// Check fallback
				var fb = this._fallbackPairs[code];
				if (!fb || !this._values[fb] || !this._values[fb].trim()) {
					return false;
				}
			}
		}
		return true;
	}

	dispose() {
		// No global event listeners to clean up
	}

	// ── DOM building ───────────────────────────────────────────

	_buildDom() {
		var self = this;
		this.container.classList.add('mlt');

		// Header: label left, tabs right
		var header = document.createElement('div');
		header.className = 'mlt__header';

		if (this.label) {
			var labelEl = document.createElement('span');
			labelEl.className = 'mlt__label';
			labelEl.textContent = this.label;
			header.appendChild(labelEl);
		}

		var tabs = document.createElement('div');
		tabs.className = 'mlt__tabs';
		tabs.setAttribute('role', 'tablist');

		for (var i = 0; i < this.languages.length; i++) {
			var lang = this.languages[i];
			var tab = document.createElement('button');
			tab.type = 'button';
			tab.className = 'mlt__tab';
			tab.setAttribute('role', 'tab');
			tab.setAttribute('data-mlt-tab', lang.code);
			tab.textContent = lang.label;
			tab.addEventListener('click', this._onTabClick.bind(this, lang.code));
			tabs.appendChild(tab);
		}

		header.appendChild(tabs);
		this.container.appendChild(header);

		// Panels
		var body = document.createElement('div');
		body.className = 'mlt__body';

		for (var j = 0; j < this.languages.length; j++) {
			var langJ = this.languages[j];
			var panel = document.createElement('div');
			panel.className = 'mlt__panel';
			panel.setAttribute('role', 'tabpanel');
			panel.setAttribute('data-mlt-panel', langJ.code);

			var input;
			if (this.inputType === 'textarea') {
				input = document.createElement('textarea');
				input.rows = 3;
			} else {
				input = document.createElement('input');
				input.type = 'text';
			}
			input.className = 'mlt__input';
			input.setAttribute('data-mlt-input', langJ.code);
			input.disabled = this.disabled;
			input.value = this._values[langJ.code] || '';

			// Placeholder
			if (typeof this.placeholder === 'object') {
				input.placeholder = this.placeholder[langJ.code] || '';
			} else if (this.placeholder) {
				input.placeholder = this.placeholder;
			}

			input.addEventListener('input', this._onInput.bind(this, langJ.code));

			// Fallback hint
			var hint = document.createElement('span');
			hint.className = 'mlt__fallback-hint';
			hint.setAttribute('data-mlt-hint', langJ.code);

			panel.appendChild(input);
			panel.appendChild(hint);
			body.appendChild(panel);
		}

		this.container.appendChild(body);
		this._built = true;
	}

	_bindExisting() {
		var self = this;
		var tabs = this.container.querySelectorAll('[data-mlt-tab]');
		for (var i = 0; i < tabs.length; i++) {
			tabs[i].addEventListener('click', this._onTabClick.bind(this, tabs[i].dataset.mltTab));
		}

		var inputs = this.container.querySelectorAll('[data-mlt-input]');
		for (var j = 0; j < inputs.length; j++) {
			var code = inputs[j].dataset.mltInput;
			inputs[j].addEventListener('input', this._onInput.bind(this, code));
			if (this._values[code]) inputs[j].value = this._values[code];
		}
		this._built = true;
	}

	// ── Events ─────────────────────────────────────────────────

	_onTabClick(langCode) {
		this._activeLang = langCode;
		this._showTab(langCode);
	}

	_onInput(langCode) {
		var input = this.container.querySelector('[data-mlt-input="' + langCode + '"]');
		if (input) {
			this._values[langCode] = input.value;
		}
		this._updateStatus();
		this._updateFallbackHints();
		this.onChange(this.getValue());
	}

	// ── Rendering ──────────────────────────────────────────────

	_showTab(langCode) {
		// Update tab active state
		var tabs = this.container.querySelectorAll('[data-mlt-tab]');
		for (var i = 0; i < tabs.length; i++) {
			var isActive = tabs[i].dataset.mltTab === langCode;
			tabs[i].classList.toggle('mlt__tab--active', isActive);
			tabs[i].setAttribute('aria-selected', isActive ? 'true' : 'false');
		}

		// Update panel visibility
		var panels = this.container.querySelectorAll('[data-mlt-panel]');
		for (var j = 0; j < panels.length; j++) {
			panels[j].hidden = panels[j].dataset.mltPanel !== langCode;
		}

		this._updateFallbackHints();
	}

	_updateStatus() {
		// Update per-tab fill indicators (dot color)
		var tabs = this.container.querySelectorAll('[data-mlt-tab]');
		for (var j = 0; j < tabs.length; j++) {
			var tabCode = tabs[j].dataset.mltTab;
			var tabVal = this._values[tabCode];
			var hasDirect = tabVal && tabVal.trim();
			var fb = this._fallbackPairs[tabCode];
			var hasFallback = !hasDirect && fb && this._values[fb] && this._values[fb].trim();

			tabs[j].classList.toggle('mlt__tab--filled', !!hasDirect);
			tabs[j].classList.toggle('mlt__tab--fallback', !!hasFallback);
			tabs[j].classList.toggle('mlt__tab--empty', !hasDirect && !hasFallback);
		}
	}

	_updateFallbackHints() {
		for (var i = 0; i < this.languages.length; i++) {
			var code = this.languages[i].code;
			var hint = this.container.querySelector('[data-mlt-hint="' + code + '"]');
			if (!hint) continue;

			var val = this._values[code];
			var fb = this._fallbackPairs[code];

			if ((!val || !val.trim()) && fb && this._values[fb] && this._values[fb].trim()) {
				var fbLang = this.languages.find(function (l) { return l.code === fb; });
				var fbLabel = fbLang ? fbLang.label : fb;
				hint.textContent = this.fallbackHintPrefix + ' ' + fbLabel;
				hint.hidden = false;
			} else {
				hint.textContent = '';
				hint.hidden = true;
			}
		}
	}
}
