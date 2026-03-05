/**
 * Reusable Search Select
 *
 * A searchable typeahead dropdown for selecting from a list of items.
 * Items can be provided directly or fetched from an API endpoint.
 *
 * Usage (inline items):
 *   var selector = new SearchSelect(containerEl, {
 *       items:       [{id: 1, name: 'Foo'}, {id: 2, name: 'Bar'}],
 *       placeholder: 'Search...',
 *       emptyText:   'No matches',
 *       allowEmpty:  true,              // adds a "none" option at top
 *       emptyLabel:  '-- None --',      // label for the empty option
 *       value:       2,                 // pre-selected id
 *       onChange:    function(id, name, item) { ... }
 *   });
 *
 * Usage (API fetch):
 *   var selector = new SearchSelect(containerEl, {
 *       apiUrl:      '/booking/resources',
 *       idField:     'id',              // default: 'id'
 *       labelField:  'name',            // default: 'name'
 *       mapResponse: function(data) { return data.results || data; },
 *       onChange:     function(id, name, item) { ... }
 *   });
 *
 * The container element should contain:
 *   - <input type="text"   class="search-select__input">
 *   - <input type="hidden"> for the selected ID
 *   - <ul class="search-select__dropdown">
 */
class SearchSelect {
    constructor(container, options) {
        this.container = container;
        this.apiUrl = options.apiUrl || null;
        this.idField = options.idField || 'id';
        this.labelField = options.labelField || 'name';
        this.mapResponse = options.mapResponse || null;
        this.allowEmpty = options.allowEmpty || false;
        this.emptyLabel = options.emptyLabel || '';
        this.emptyText = options.emptyText || 'No matches';
        this.placeholder = options.placeholder || '';
        this.onChange = options.onChange || function () {};

        this.input = container.querySelector('.search-select__input');
        this.hiddenInput = container.querySelector('input[type="hidden"]');
        this.dropdown = container.querySelector('.search-select__dropdown');

        if (this.placeholder && this.input) {
            this.input.placeholder = this.placeholder;
        }

        this.items = null;
        this.filtered = [];
        this.highlightIndex = -1;
        this.selectedId = options.value != null ? options.value : null;
        this.selectedName = '';
        this.isOpen = false;
        this.loading = false;

        // If items provided directly, use them
        if (options.items) {
            this.items = options.items;
            // Resolve initial display name
            if (this.selectedId != null) {
                var self = this;
                var found = this.items.find(function (item) {
                    return item[self.idField] === self.selectedId;
                });
                if (found) {
                    this.selectedName = found[this.labelField];
                    this.input.value = this.selectedName;
                } else if (this.allowEmpty && (this.selectedId === null || this.selectedId === '')) {
                    this.input.value = this.emptyLabel;
                }
            }
        }

        this._boundOnFocus = this._onFocus.bind(this);
        this._boundOnInput = this._onInput.bind(this);
        this._boundOnKeyDown = this._onKeyDown.bind(this);
        this._boundOnDocumentClick = this._onDocumentClick.bind(this);

        this._bindEvents();
    }

    _bindEvents() {
        this.input.addEventListener('focus', this._boundOnFocus);
        this.input.addEventListener('input', this._boundOnInput);
        this.input.addEventListener('keydown', this._boundOnKeyDown);
        document.addEventListener('click', this._boundOnDocumentClick);
    }

    _onFocus() {
        this.input.select();
        if (!this.items) {
            this._fetchItems().then(function () {
                this._filter();
                this._open();
            }.bind(this));
        } else {
            this._filter();
            this._open();
        }
    }

    _onInput() {
        if (!this.items) {
            this._fetchItems().then(function () {
                this._filter();
                this._open();
            }.bind(this));
            return;
        }
        this._filter();
        this._open();
    }

    _onKeyDown(e) {
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
                this.input.value = this.selectedName || (this.allowEmpty ? this.emptyLabel : '');
                break;
        }
    }

    _onDocumentClick(e) {
        if (!this.container.contains(e.target)) {
            this._close();
            this.input.value = this.selectedName || (this.allowEmpty ? this.emptyLabel : '');
        }
    }

    _fetchItems() {
        if (this.loading || !this.apiUrl) return Promise.resolve();
        this.loading = true;

        var self = this;
        return fetch(this.apiUrl, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                self.items = self.mapResponse ? self.mapResponse(data) : (Array.isArray(data) ? data : []);
                self.loading = false;
            })
            .catch(function (err) {
                console.error('SearchSelect: failed to fetch items', err);
                self.items = [];
                self.loading = false;
            });
    }

    _filter() {
        var query = this.input.value.toLowerCase().trim();
        var labelField = this.labelField;

        if (!query) {
            this.filtered = this.items.slice();
        } else {
            this.filtered = this.items.filter(function (item) {
                return String(item[labelField] || '').toLowerCase().indexOf(query) !== -1;
            });
        }

        // Prepend empty option
        if (this.allowEmpty) {
            var emptyItem = {};
            emptyItem[this.idField] = null;
            emptyItem[this.labelField] = this.emptyLabel;
            emptyItem._isEmpty = true;
            this.filtered.unshift(emptyItem);
        }

        this.highlightIndex = -1;
        this._renderDropdown();
    }

    _renderDropdown() {
        this.dropdown.innerHTML = '';

        if (this.filtered.length === 0 || (this.filtered.length === 1 && this.filtered[0]._isEmpty)) {
            var empty = document.createElement('li');
            empty.className = 'search-select__empty';
            empty.textContent = this.emptyText;
            this.dropdown.appendChild(empty);
            return;
        }

        for (var i = 0; i < this.filtered.length; i++) {
            var it = this.filtered[i];
            var item = document.createElement('li');
            item.className = 'search-select__item';
            item.setAttribute('role', 'option');
            item.textContent = it[this.labelField] || '';

            if (it._isEmpty) {
                item.style.fontStyle = 'italic';
                item.style.color = 'var(--ds-color-text-subtle, #666)';
            }

            if (it[this.idField] === this.selectedId) {
                item.classList.add('search-select__item--selected');
            }
            if (i === this.highlightIndex) {
                item.classList.add('search-select__item--highlighted');
            }

            item.addEventListener('click', this._onItemClick.bind(this, it));
            this.dropdown.appendChild(item);
        }
    }

    _onItemClick(item, e) {
        e.preventDefault();
        e.stopPropagation();
        this._select(item);
    }

    _select(item) {
        this.selectedId = item[this.idField];
        this.selectedName = item._isEmpty ? this.emptyLabel : (item[this.labelField] || '');
        this.input.value = this.selectedName;
        if (this.hiddenInput) this.hiddenInput.value = this.selectedId != null ? this.selectedId : '';
        this._close();
        this.onChange(this.selectedId, this.selectedName, item);
    }

    _open() {
        this.isOpen = true;
        this.dropdown.classList.add('search-select__dropdown--open');
        this.input.setAttribute('aria-expanded', 'true');
    }

    _close() {
        this.isOpen = false;
        this.highlightIndex = -1;
        this.dropdown.classList.remove('search-select__dropdown--open');
        this.input.setAttribute('aria-expanded', 'false');
    }

    _moveHighlight(direction) {
        this.highlightIndex += direction;
        if (this.highlightIndex < 0) this.highlightIndex = 0;
        if (this.highlightIndex >= this.filtered.length) this.highlightIndex = this.filtered.length - 1;

        var items = this.dropdown.querySelectorAll('.search-select__item');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('search-select__item--highlighted', i === this.highlightIndex);
        }
        if (items[this.highlightIndex]) {
            items[this.highlightIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    getValue() {
        return this.selectedId;
    }

    getDisplayValue() {
        return this.selectedName;
    }

    setValue(id, name) {
        this.selectedId = id;
        this.selectedName = name || '';
        this.input.value = this.selectedName;
        if (this.hiddenInput) this.hiddenInput.value = id != null ? id : '';
    }

    dispose() {
        this.input.removeEventListener('focus', this._boundOnFocus);
        this.input.removeEventListener('input', this._boundOnInput);
        this.input.removeEventListener('keydown', this._boundOnKeyDown);
        document.removeEventListener('click', this._boundOnDocumentClick);
    }
}
