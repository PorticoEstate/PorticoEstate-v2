/**
 * Reusable Building Selector
 *
 * Renders a searchable dropdown of buildings fetched from an API endpoint.
 * Vanilla JS, no dependencies.
 *
 * Usage:
 *   var selector = new BuildingSelect(containerEl, {
 *       apiUrl: '/booking/buildings',
 *       value: 42,
 *       displayValue: 'Eidsvåg skole',
 *       onChange: function(id, name) { ... }
 *   });
 *
 * The container element should contain:
 *   - An <input type="text"> with class "building-select__input"
 *   - An <input type="hidden"> (second input) for the selected ID
 *   - A <ul> with class "building-select__dropdown"
 */
class BuildingSelect {
    constructor(container, options) {
        this.container = container;
        this.apiUrl = options.apiUrl || container.dataset.apiUrl;
        this.onChange = options.onChange || function () {};

        this.input = container.querySelector('.building-select__input');
        this.hiddenInput = container.querySelector('input[type="hidden"]');
        this.dropdown = container.querySelector('.building-select__dropdown');

        this.buildings = null; // cached list from API
        this.filtered = [];
        this.highlightIndex = -1;
        this.selectedId = options.value || parseInt(container.dataset.value, 10) || null;
        this.selectedName = options.displayValue || container.dataset.display || '';
        this.isOpen = false;
        this.loading = false;

        // Store bound handlers for proper cleanup
        this._boundOnFocus = this._onFocus.bind(this);
        this._boundOnInput = this._onInput.bind(this);
        this._boundOnKeyDown = this._onKeyDown.bind(this);
        this._boundOnDocumentClick = this._onDocumentClick.bind(this);

        // Set initial display value
        if (this.selectedName) {
            this.input.value = this.selectedName;
        }

        this._bindEvents();
    }

    _bindEvents() {
        this.input.addEventListener('focus', this._boundOnFocus);
        this.input.addEventListener('input', this._boundOnInput);
        this.input.addEventListener('keydown', this._boundOnKeyDown);
        document.addEventListener('click', this._boundOnDocumentClick);
    }

    _onFocus() {
        if (!this.buildings) {
            this._fetchBuildings().then(function () {
                this._filter();
                this._open();
            }.bind(this));
        } else {
            this._filter();
            this._open();
        }
    }

    _onInput() {
        if (!this.buildings) {
            this._fetchBuildings().then(function () {
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
                // Restore displayed name
                this.input.value = this.selectedName || '';
                break;
        }
    }

    _onDocumentClick(e) {
        if (!this.container.contains(e.target)) {
            this._close();
            // Restore displayed name if user clicked away
            this.input.value = this.selectedName || '';
        }
    }

    _fetchBuildings() {
        if (this.loading) return Promise.resolve();
        this.loading = true;

        return fetch(this.apiUrl, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                this.buildings = data;
                this.loading = false;
            }.bind(this))
            .catch(function (err) {
                console.error('BuildingSelect: failed to fetch buildings', err);
                this.buildings = [];
                this.loading = false;
            }.bind(this));
    }

    _filter() {
        var query = this.input.value.toLowerCase().trim();
        if (!query) {
            this.filtered = this.buildings.slice();
        } else {
            this.filtered = this.buildings.filter(function (b) {
                return b.name.toLowerCase().indexOf(query) !== -1;
            });
        }
        this.highlightIndex = -1;
        this._renderDropdown();
    }

    _renderDropdown() {
        this.dropdown.innerHTML = '';

        if (this.filtered.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'building-select__empty';
            empty.textContent = 'Ingen treff';
            this.dropdown.appendChild(empty);
            return;
        }

        for (var i = 0; i < this.filtered.length; i++) {
            var item = document.createElement('li');
            item.className = 'building-select__item';
            item.setAttribute('role', 'option');
            item.dataset.id = this.filtered[i].id;
            item.textContent = this.filtered[i].name;

            if (this.filtered[i].id === this.selectedId) {
                item.classList.add('building-select__item--selected');
            }
            if (i === this.highlightIndex) {
                item.classList.add('building-select__item--highlighted');
            }

            item.addEventListener('click', this._onItemClick.bind(this, this.filtered[i]));
            this.dropdown.appendChild(item);
        }
    }

    _onItemClick(building, e) {
        e.preventDefault();
        e.stopPropagation();
        this._select(building);
    }

    _select(building) {
        this.selectedId = building.id;
        this.selectedName = building.name;
        this.input.value = building.name;
        this.hiddenInput.value = building.id;
        this._close();
        this.onChange(building.id, building.name);
    }

    _open() {
        this.isOpen = true;
        this.dropdown.classList.add('building-select__dropdown--open');
        this.input.setAttribute('aria-expanded', 'true');
    }

    _close() {
        this.isOpen = false;
        this.highlightIndex = -1;
        this.dropdown.classList.remove('building-select__dropdown--open');
        this.input.setAttribute('aria-expanded', 'false');
    }

    _moveHighlight(direction) {
        this.highlightIndex += direction;
        if (this.highlightIndex < 0) this.highlightIndex = 0;
        if (this.highlightIndex >= this.filtered.length) this.highlightIndex = this.filtered.length - 1;

        var items = this.dropdown.querySelectorAll('.building-select__item');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('building-select__item--highlighted', i === this.highlightIndex);
        }

        // Scroll highlighted item into view
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
        this.selectedName = name;
        this.input.value = name;
        this.hiddenInput.value = id;
    }

    dispose() {
        this.input.removeEventListener('focus', this._boundOnFocus);
        this.input.removeEventListener('input', this._boundOnInput);
        this.input.removeEventListener('keydown', this._boundOnKeyDown);
        document.removeEventListener('click', this._boundOnDocumentClick);
    }
}
