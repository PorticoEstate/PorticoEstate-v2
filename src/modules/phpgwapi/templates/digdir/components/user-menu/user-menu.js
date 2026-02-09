/**
 * User Menu Component
 * Wires up event listeners for the server-rendered user menu.
 */
(function () {
	'use strict';

	function init(el) {
		const popover = el.querySelector('[popover]');
		const trigger = el.querySelector('[popovertarget]');
		if (!popover || !trigger) return;

		popover.addEventListener('toggle', (e) => {
			trigger.setAttribute('aria-expanded', e.newState === 'open');
		});

		const logoutBtn = el.querySelector('#userMenuLogout');
		if (logoutBtn) {
			logoutBtn.addEventListener('click', (e) => {
				e.preventDefault();
				popover.hidePopover();
				document.getElementById('logoutModal').showModal();
			});
		}
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
