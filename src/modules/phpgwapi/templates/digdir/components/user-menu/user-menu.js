/**
 * User Menu Component
 * Wires up event listeners for the server-rendered user menu.
 * On mobile, moves topbar nav items into the user menu popover.
 */
(function () {
	'use strict';

	var MOBILE_BREAKPOINT = 991;

	function init(el) {
		var popover = el.querySelector('[popover]');
		var trigger = el.querySelector('[popovertarget]');
		if (!popover || !trigger) return;

		popover.addEventListener('toggle', function (e) {
			trigger.setAttribute('aria-expanded', e.newState === 'open');
			if (e.newState === 'open') {
				var rect = trigger.getBoundingClientRect();
				popover.style.top = rect.bottom + 'px';
				popover.style.left = 'auto';
				popover.style.right = (window.innerWidth - rect.right) + 'px';
			}
		});

		var logoutBtn = el.querySelector('[data-action="logout"]');
		if (logoutBtn) {
			logoutBtn.addEventListener('click', function (e) {
				e.preventDefault();
				if (typeof popover.hidePopover === 'function') {
					popover.hidePopover();
				}
				var logoutModal = document.getElementById('logoutModal');
				if (logoutModal && typeof logoutModal.showModal === 'function') {
					logoutModal.showModal();
				} else if (logoutModal) {
					logoutModal.classList.add('show');
					logoutModal.style.display = 'block';
				}
			});
		}

		initMobileNav(el);
	}

	/**
	 * On mobile, move topbar nav items into the user menu popover.
	 * Uses move (not clone) for JS-driven components so their event listeners survive.
	 * On resize back to desktop, items are moved back to the topbar.
	 */
	function initMobileNav(el) {
		var mobileNavContainer = el.querySelector('#userMenuMobileNav');
		var navItemsWrapper = document.querySelector('.app-topbar__nav-items');
		if (!mobileNavContainer || !navItemsWrapper) return;

		var mobileList = document.createElement('ul');
		mobileList.className = 'app-user-menu__mobile-list';

		var divider = document.createElement('hr');
		divider.className = 'app-user-menu__divider';

		// Track moved elements for restoring on desktop
		var movedElements = [];

		function buildMobileMenu() {
			// Clear previous
			mobileList.innerHTML = '';
			movedElements = [];

			var children = Array.prototype.slice.call(navItemsWrapper.children);
			for (var i = 0; i < children.length; i++) {
				var item = children[i];

				if (item.tagName === 'A') {
					// Simple link
					var li = document.createElement('li');
					var link = document.createElement('a');
					link.className = 'ds-button';
					link.setAttribute('data-variant', 'tertiary');
					link.href = item.href;
					link.textContent = item.textContent.trim();
					li.appendChild(link);
					mobileList.appendChild(li);
				} else if (item.tagName === 'SELECT') {
					// Template selector — clone (no complex JS)
					var selectLi = document.createElement('li');
					var clone = item.cloneNode(true);
					clone.id = clone.id ? clone.id + '_mobile' : '';
					clone.className = 'app-user-menu__mobile-select';
					clone.addEventListener('change', (function (original) {
						return function () {
							original.value = this.value;
							original.dispatchEvent(new Event('change', { bubbles: true }));
						};
					})(item));
					selectLi.appendChild(clone);
					mobileList.appendChild(selectLi);
				} else if (item.classList && item.classList.contains('app-dropdown')) {
					// Dropdown (bookmarks) — flatten into links
					var dropdownItems = item.querySelectorAll('.app-dropdown__item');
					if (dropdownItems.length > 0) {
						var triggerEl = item.querySelector('.app-dropdown__trigger');
						var headerLi = document.createElement('li');
						headerLi.className = 'app-user-menu__mobile-header';
						headerLi.textContent = triggerEl ? triggerEl.textContent.trim() : '';
						mobileList.appendChild(headerLi);

						for (var j = 0; j < dropdownItems.length; j++) {
							var bmLi = document.createElement('li');
							var bmLink = document.createElement('a');
							bmLink.className = 'ds-button';
							bmLink.setAttribute('data-variant', 'tertiary');
							bmLink.href = dropdownItems[j].href || '#';
							bmLink.textContent = dropdownItems[j].textContent.trim();
							bmLi.appendChild(bmLink);
							mobileList.appendChild(bmLi);
						}
					}
				} else if (item.classList && item.classList.contains('app-language-selector')) {
					// JS-driven component — move the original element
					var langLi = document.createElement('li');
					langLi.className = 'app-user-menu__mobile-lang';
					langLi.appendChild(item);
					mobileList.appendChild(langLi);
					movedElements.push({ element: item, parent: navItemsWrapper, index: i });
				} else if (item.classList && item.classList.contains('app-nav-item')) {
					// Messenger
					var msgLink = item.querySelector('a');
					if (msgLink) {
						var msgLi = document.createElement('li');
						var msgA = document.createElement('a');
						msgA.className = 'ds-button';
						msgA.setAttribute('data-variant', 'tertiary');
						msgA.href = msgLink.href || '#';
						var icon = msgLink.querySelector('i');
						var badge = msgLink.querySelector('.app-badge');
						msgA.textContent = icon ? ' Messenger' : msgLink.textContent.trim();
						if (badge) {
							msgA.textContent += ' (' + badge.textContent.trim() + ')';
						}
						msgLi.appendChild(msgA);
						mobileList.appendChild(msgLi);
					}
				}
			}

			if (mobileList.children.length > 0) {
				mobileNavContainer.innerHTML = '';
				mobileNavContainer.appendChild(mobileList);
				mobileNavContainer.appendChild(divider);
			}
		}

		function restoreDesktop() {
			// Move elements back to original positions
			for (var i = 0; i < movedElements.length; i++) {
				var entry = movedElements[i];
				var ref = navItemsWrapper.children[entry.index];
				if (ref) {
					navItemsWrapper.insertBefore(entry.element, ref);
				} else {
					navItemsWrapper.appendChild(entry.element);
				}
			}
			movedElements = [];
			mobileNavContainer.innerHTML = '';
		}

		var isMobile = window.innerWidth <= MOBILE_BREAKPOINT;

		if (isMobile) {
			buildMobileMenu();
		}

		var resizeTimer;
		window.addEventListener('resize', function () {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(function () {
				var nowMobile = window.innerWidth <= MOBILE_BREAKPOINT;
				if (nowMobile && !isMobile) {
					buildMobileMenu();
				} else if (!nowMobile && isMobile) {
					restoreDesktop();
				}
				isMobile = nowMobile;
			}, 150);
		});
	}

	function initAll() {
		document.querySelectorAll('[data-component="user-menu"]').forEach(init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
