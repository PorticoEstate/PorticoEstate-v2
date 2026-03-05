/**
 * Article Mapping Selector
 *
 * Searchable typeahead for selecting article mappings (from the legacy
 * uiarticle_mapping endpoint). Filters to a given category and excludes
 * already-used mapping IDs.
 *
 * Usage:
 *   var selector = new ArticleSelect(containerEl, {
 *       category:    'service',          // article_cat_name filter
 *       excludeIds:  [859, 860],         // article_mapping_ids to hide
 *       emptyText:   'No matches',
 *       onChange:     function(article) { ... }
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

        var items = this.dropdown.querySelectorAll('.article-select__item');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('article-select__item--highlighted', i === this.highlightIndex);
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

    dispose() {
        this.input.removeEventListener('focus', this._boundOnFocus);
        this.input.removeEventListener('input', this._boundOnInput);
        this.input.removeEventListener('keydown', this._boundOnKeyDown);
        document.removeEventListener('click', this._boundOnDocumentClick);
    }
}
