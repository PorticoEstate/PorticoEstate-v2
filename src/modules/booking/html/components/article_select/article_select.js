/**
 * Article Mapping Selector
 *
 * Searchable typeahead for selecting article mappings (from the legacy
 * uiarticle_mapping endpoint). Filters to a given category and excludes
 * already-used mapping IDs.
 *
 * Supports inline creation of new article mappings via the "Add New" option.
 *
 * Usage:
 *   var selector = new ArticleSelect(containerEl, {
 *       category:    'service',          // article_cat_name filter
 *       excludeIds:  [859, 860],         // article_mapping_ids to hide
 *       emptyText:   'No matches',
 *       onChange:     function(article) { ... },
 *       createEndpoint: '/booking/article-mappings',  // POST endpoint for creation
 *       taxListEndpoint: '/booking/registry/tax/list', // GET endpoint for tax codes
 *       lang: {                          // i18n labels
 *           createNew: 'Create new article',
 *           backToSearch: 'Back to search',
 *           articleCode: 'Article code',
 *           defaultPrice: 'Default price',
 *           creationFailed: 'Creation failed',
 *           name: 'Name',
 *           unit: 'Unit',
 *           taxCode: 'Tax code',
 *           save: 'Save',
 *           cancel: 'Cancel'
 *       }
 *   });
 *
 * The container element should contain:
 *   - <input type="text"   class="article-select__input">
 *   - <input type="hidden"> for the selected ID
 *   - <ul class="article-select__dropdown">
 */
class ArticleSelect {
    constructor(container, options) {
        this.container = container;
        this.category = options.category || null;
        this.excludeIds = options.excludeIds || [];
        this.emptyText = options.emptyText || 'No matches';
        this.onChange = options.onChange || function () {};
        this.createEndpoint = options.createEndpoint || null;
        this.taxListEndpoint = options.taxListEndpoint || null;
        this.lang = options.lang || {};

        this.input = container.querySelector('.article-select__input');
        this.hiddenInput = container.querySelector('input[type="hidden"]');
        this.dropdown = container.querySelector('.article-select__dropdown');

        this.articles = null; // cached filtered list
        this.filtered = [];
        this.highlightIndex = -1;
        this.selectedId = null;
        this.selectedName = '';
        this.isOpen = false;
        this.loading = false;
        this.inCreateMode = false;
        this._taxSelector = null;

        this._boundOnFocus = this._onFocus.bind(this);
        this._boundOnInput = this._onInput.bind(this);
        this._boundOnKeyDown = this._onKeyDown.bind(this);
        this._boundOnDocumentClick = this._onDocumentClick.bind(this);

        this._bindEvents();
    }

    _l(key) {
        return this.lang[key] || key;
    }

    _bindEvents() {
        this.input.addEventListener('focus', this._boundOnFocus);
        this.input.addEventListener('input', this._boundOnInput);
        this.input.addEventListener('keydown', this._boundOnKeyDown);
        document.addEventListener('click', this._boundOnDocumentClick);
    }

    _onFocus() {
        if (this.inCreateMode) return;
        if (!this.articles) {
            this._fetchArticles().then(function () {
                this._filter();
                this._open();
            }.bind(this));
        } else {
            this._filter();
            this._open();
        }
    }

    _onInput() {
        if (this.inCreateMode) return;
        if (!this.articles) {
            this._fetchArticles().then(function () {
                this._filter();
                this._open();
            }.bind(this));
            return;
        }
        this._filter();
        this._open();
    }

    _onKeyDown(e) {
        if (this.inCreateMode) return;
        if (!this.isOpen) {
            if (e.key === 'ArrowDown' || e.key === 'Enter') {
                e.preventDefault();
                this._onFocus();
            }
            return;
        }

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this._moveHighlight(1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                this._moveHighlight(-1);
                break;
            case 'Enter':
                e.preventDefault();
                if (this.highlightIndex >= 0 && this.highlightIndex < this.filtered.length) {
                    this._select(this.filtered[this.highlightIndex]);
                }
                break;
            case 'Escape':
                e.preventDefault();
                this._close();
                this.input.value = this.selectedName || '';
                break;
        }
    }

    _onDocumentClick(e) {
        if (!this.container.contains(e.target)) {
            if (this.inCreateMode) return;
            this._close();
            this.input.value = this.selectedName || '';
        }
    }

    _fetchArticles() {
        if (this.loading) return Promise.resolve();
        this.loading = true;

        var self = this;
        return fetch('/?menuaction=booking.uiarticle_mapping.index&phpgw_return_as=json&length=-1', {
            credentials: 'same-origin'
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                var all = data.data || [];
                // Apply category filter and exclude already-used IDs
                self.articles = all.filter(function (m) {
                    if (!m.active) return false;
                    if (self.category && m.article_cat_name !== self.category) return false;
                    if (self.excludeIds.indexOf(m.id) !== -1) return false;
                    return true;
                });
                self.loading = false;
            })
            .catch(function (err) {
                console.error('ArticleSelect: failed to fetch articles', err);
                self.articles = [];
                self.loading = false;
            });
    }

    _filter() {
        var query = this.input.value.toLowerCase().trim();
        if (!query) {
            this.filtered = this.articles.slice();
        } else {
            this.filtered = this.articles.filter(function (a) {
                return a.article_name.toLowerCase().indexOf(query) !== -1 ||
                    (a.article_code && a.article_code.toLowerCase().indexOf(query) !== -1);
            });
        }
        this.highlightIndex = -1;
        this._renderDropdown();
    }

    _renderDropdown() {
        this.dropdown.innerHTML = '';

        // "Add New" item at the top (if creation is enabled)
        if (this.createEndpoint) {
            var addNew = document.createElement('li');
            addNew.className = 'article-select__item article-select__item--add-new';
            addNew.setAttribute('role', 'option');
            addNew.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> ' +
                this._escHtml(this._l('createNew'));
            addNew.addEventListener('click', this._enterCreateMode.bind(this));
            this.dropdown.appendChild(addNew);
        }

        if (this.filtered.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'article-select__empty';
            empty.textContent = this.emptyText;
            this.dropdown.appendChild(empty);
            return;
        }

        for (var i = 0; i < this.filtered.length; i++) {
            var a = this.filtered[i];
            var item = document.createElement('li');
            item.className = 'article-select__item';
            item.setAttribute('role', 'option');
            item.dataset.id = a.id;

            var nameSpan = document.createElement('div');
            nameSpan.textContent = a.article_name;
            item.appendChild(nameSpan);

            var metaSpan = document.createElement('div');
            metaSpan.className = 'article-select__item-meta';
            metaSpan.textContent = a.unit + (a.article_group ? ' · ' + a.article_group : '');
            item.appendChild(metaSpan);

            if (a.id === this.selectedId) {
                item.classList.add('article-select__item--selected');
            }
            if (i === this.highlightIndex) {
                item.classList.add('article-select__item--highlighted');
            }

            item.addEventListener('click', this._onItemClick.bind(this, a));
            this.dropdown.appendChild(item);
        }
    }

    _onItemClick(article, e) {
        e.preventDefault();
        e.stopPropagation();
        this._select(article);
    }

    _select(article) {
        this.selectedId = article.id;
        this.selectedName = article.article_name;
        this.input.value = article.article_name;
        if (this.hiddenInput) this.hiddenInput.value = article.id;
        this._close();
        this.onChange(article);
    }

    _open() {
        this.isOpen = true;
        this.dropdown.classList.add('article-select__dropdown--open');
        this.input.setAttribute('aria-expanded', 'true');
    }

    _close() {
        this.isOpen = false;
        this.highlightIndex = -1;
        this.dropdown.classList.remove('article-select__dropdown--open');
        this.input.setAttribute('aria-expanded', 'false');
    }

    _moveHighlight(direction) {
        this.highlightIndex += direction;
        if (this.highlightIndex < 0) this.highlightIndex = 0;
        if (this.highlightIndex >= this.filtered.length) this.highlightIndex = this.filtered.length - 1;

        var items = this.dropdown.querySelectorAll('.article-select__item:not(.article-select__item--add-new)');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('article-select__item--highlighted', i === this.highlightIndex);
        }
        if (items[this.highlightIndex]) {
            items[this.highlightIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    // ── Inline creation ──────────────────────────────────────────────

    _enterCreateMode(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        this.inCreateMode = true;
        this.input.style.display = 'none';
        this.dropdown.classList.remove('article-select__dropdown--open');

        // Remove any existing create form
        var existing = this.container.querySelector('.article-select__create-form');
        if (existing) existing.remove();

        var form = document.createElement('div');
        form.className = 'article-select__create-form';

        var backBtn = document.createElement('button');
        backBtn.type = 'button';
        backBtn.className = 'article-select__create-back';
        backBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg> ' +
            this._escHtml(this._l('backToSearch'));
        backBtn.addEventListener('click', this._exitCreateMode.bind(this));
        form.appendChild(backBtn);

        var fields = document.createElement('div');
        fields.className = 'article-select__create-fields';

        // Name
        fields.appendChild(this._createField('create-name', this._l('name') + ' *', 'text'));
        // Article code
        fields.appendChild(this._createField('create-code', this._l('articleCode') + ' *', 'text'));
        // Unit (select)
        var unitField = this._createSelectField('create-unit', this._l('unit') + ' *', [
            { value: 'each', label: 'each' },
            { value: 'kg', label: 'kg' },
            { value: 'm', label: 'm' },
            { value: 'm2', label: 'm\u00B2' },
            { value: 'minute', label: 'minute' },
            { value: 'hour', label: 'hour' },
            { value: 'day', label: 'day' }
        ]);
        fields.appendChild(unitField);
        // Tax code (SearchSelect)
        var taxField = document.createElement('div');
        taxField.className = 'article-select__create-field';
        var taxLabel = document.createElement('label');
        taxLabel.textContent = this._l('taxCode') + ' *';
        taxField.appendChild(taxLabel);
        var taxContainer = document.createElement('div');
        taxContainer.className = 'search-select';
        taxContainer.innerHTML =
            '<input type="text" class="search-select__input article-select__create-input" autocomplete="off" aria-expanded="false" aria-autocomplete="list" role="combobox">' +
            '<input type="hidden" id="create-tax">' +
            '<ul class="search-select__dropdown" role="listbox"></ul>';
        taxField.appendChild(taxContainer);
        fields.appendChild(taxField);
        // Price
        fields.appendChild(this._createField('create-price', this._l('defaultPrice'), 'number', '0.01'));

        form.appendChild(fields);

        // Error display
        var errEl = document.createElement('div');
        errEl.className = 'article-select__create-error';
        errEl.hidden = true;
        form.appendChild(errEl);

        this.container.appendChild(form);

        // Initialize tax code SearchSelect
        this._taxSelector = new SearchSelect(taxContainer, {
            apiUrl: this.taxListEndpoint,
            mapResponse: function (resp) {
                return Array.isArray(resp) ? resp : (resp.data || []);
            },
            placeholder: this._l('taxCode') + '...',
            emptyText: this._l('taxCode')
        });

        // Focus name field
        var nameInput = form.querySelector('#create-name');
        if (nameInput) nameInput.focus();
    }

    _exitCreateMode() {
        this.inCreateMode = false;
        this.input.style.display = '';
        if (this._taxSelector) {
            this._taxSelector.dispose();
            this._taxSelector = null;
        }
        var form = this.container.querySelector('.article-select__create-form');
        if (form) form.remove();
        this.input.focus();
    }

    _createField(id, label, type, step) {
        var wrapper = document.createElement('div');
        wrapper.className = 'article-select__create-field';
        var lbl = document.createElement('label');
        lbl.setAttribute('for', id);
        lbl.textContent = label;
        wrapper.appendChild(lbl);
        var inp = document.createElement('input');
        inp.type = type;
        inp.id = id;
        inp.className = 'article-select__create-input';
        if (step) inp.step = step;
        wrapper.appendChild(inp);
        return wrapper;
    }

    _createSelectField(id, label, options) {
        var wrapper = document.createElement('div');
        wrapper.className = 'article-select__create-field';
        var lbl = document.createElement('label');
        lbl.setAttribute('for', id);
        lbl.textContent = label;
        wrapper.appendChild(lbl);
        var sel = document.createElement('select');
        sel.id = id;
        sel.className = 'article-select__create-input';
        for (var i = 0; i < options.length; i++) {
            var opt = document.createElement('option');
            opt.value = options[i].value;
            opt.textContent = options[i].label;
            sel.appendChild(opt);
        }
        wrapper.appendChild(sel);
        return wrapper;
    }

    /**
     * Submit the inline create form. Returns a Promise that resolves
     * to the new article_mapping_id, or rejects on validation/API error.
     */
    submitCreate() {
        var form = this.container.querySelector('.article-select__create-form');
        if (!form) return Promise.reject(new Error('No create form'));

        var errEl = form.querySelector('.article-select__create-error');
        errEl.hidden = true;

        var name = (form.querySelector('#create-name').value || '').trim();
        var code = (form.querySelector('#create-code').value || '').trim();
        var unit = form.querySelector('#create-unit').value;
        var taxCode = this._taxSelector ? this._taxSelector.getValue() : null;
        var priceVal = form.querySelector('#create-price').value;

        if (!name || !code || !unit || !taxCode) {
            var msg = this._l('creationFailed') + ': fill all required fields';
            errEl.textContent = msg;
            errEl.hidden = false;
            return Promise.reject(new Error(msg));
        }

        var payload = {
            name: name,
            article_code: code,
            unit: unit,
            tax_code: parseInt(taxCode, 10)
        };
        if (priceVal !== '' && !isNaN(parseFloat(priceVal))) {
            payload.price = parseFloat(priceVal);
        }

        var self = this;
        return fetch(this.createEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) {
                return r.json().then(function (json) {
                    if (!r.ok) throw new Error(json.error || 'HTTP ' + r.status);
                    return json;
                });
            })
            .then(function (mapping) {
                self.articles = null;
                self._exitCreateMode();
                self._select({
                    id: mapping.id,
                    article_name: mapping.article_name
                });
                return mapping.id;
            })
            .catch(function (err) {
                errEl.textContent = self._l('creationFailed') + ': ' + err.message;
                errEl.hidden = false;
                throw err;
            });
    }

    _escHtml(str) {
        var div = document.createElement('div');
        div.textContent = String(str || '');
        return div.innerHTML;
    }

    // ── Public API ───────────────────────────────────────────────────

    getValue() {
        return this.selectedId;
    }

    getDisplayValue() {
        return this.selectedName;
    }

    dispose() {
        this.input.removeEventListener('focus', this._boundOnFocus);
        this.input.removeEventListener('input', this._boundOnInput);
        this.input.removeEventListener('keydown', this._boundOnKeyDown);
        document.removeEventListener('click', this._boundOnDocumentClick);
    }
}
