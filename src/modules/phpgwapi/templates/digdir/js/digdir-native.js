/**
 * Native Designsystemet Dropdown and UI functionality
 * Replaces Bootstrap JavaScript dependencies
 */

(function() {
	'use strict';

	// Dropdown functionality
	class Dropdown {
		constructor(element) {
			this.element = element;
			this.trigger = element.querySelector('[data-toggle="dropdown"], [data-bs-toggle="dropdown"]');
			this.menu = element.querySelector('.app-dropdown-menu');
			
			if (!this.trigger || !this.menu) return;
			
			this.isOpen = false;
			this.init();
		}

		init() {
			// Toggle on click
			this.trigger.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				this.toggle();
			});

			// Close when clicking outside
			document.addEventListener('click', (e) => {
				if (this.isOpen && !this.element.contains(e.target)) {
					this.close();
				}
			});

			// Prevent menu from closing when clicking inside
			this.menu.addEventListener('click', (e) => {
				// Allow links and buttons to work, but stop propagation for forms
				const isInteractive = e.target.tagName === 'A' || 
									 e.target.tagName === 'BUTTON' ||
									 e.target.closest('a') || 
									 e.target.closest('button');
				
				if (!isInteractive) {
					e.stopPropagation();
				}
			});

			// Close on escape key
			document.addEventListener('keydown', (e) => {
				if (e.key === 'Escape' && this.isOpen) {
					this.close();
					this.trigger.focus();
				}
			});
		}

		toggle() {
			if (this.isOpen) {
				this.close();
			} else {
				this.open();
			}
		}

		open() {
			// Close all other dropdowns
			document.querySelectorAll('.app-dropdown.show').forEach(dropdown => {
				if (dropdown !== this.element) {
					dropdown.classList.remove('show');
				}
			});

			this.element.classList.add('show');
			this.isOpen = true;
			this.trigger.setAttribute('aria-expanded', 'true');

			// Focus first focusable element in menu
			setTimeout(() => {
				const firstFocusable = this.menu.querySelector('a, button, input, [tabindex="0"]');
				if (firstFocusable) {
					firstFocusable.focus();
				}
			}, 10);
		}

		close() {
			this.element.classList.remove('show');
			this.isOpen = false;
			this.trigger.setAttribute('aria-expanded', 'false');
		}
	}

	// Sidebar toggle functionality
	function initSidebarToggle() {
		const toggleBtn = document.getElementById('sidebarToggle');
		const sidebar = document.querySelector('.app-sidebar');
		const main = document.querySelector('.app-main');

		if (!toggleBtn || !sidebar) return;

		toggleBtn.addEventListener('click', () => {
			sidebar.classList.toggle('app-sidebar--collapsed');
			sidebar.classList.toggle('show');
			
			if (main) {
				main.classList.toggle('app-main--expanded');
			}

			// Save state to localStorage
			const isCollapsed = sidebar.classList.contains('app-sidebar--collapsed');
			localStorage.setItem('sidebar-collapsed', isCollapsed);
		});

		// Restore saved state
		const savedState = localStorage.getItem('sidebar-collapsed');
		if (savedState === 'true') {
			sidebar.classList.add('app-sidebar--collapsed');
			if (main) {
				main.classList.add('app-main--expanded');
			}
		}

		// Close sidebar on mobile when clicking outside
		if (window.innerWidth < 992) {
			document.addEventListener('click', (e) => {
				if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
					sidebar.classList.add('app-sidebar--collapsed');
					sidebar.classList.remove('show');
					if (main) {
						main.classList.remove('app-main--expanded');
					}
				}
			});
		}
	}

	// Template selector functionality
	function initTemplateSelector() {
		const selector = document.getElementById('template_selector');
		
		if (!selector) return;

		selector.addEventListener('change', function() {
			const selectedTemplate = this.value;
			
			// Save to preferences via API
			fetch('/?menuaction=preferences.uisettings.save_preference', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					app: 'common',
					name: 'template_set',
					value: selectedTemplate
				})
			})
			.then(response => response.json())
			.then(data => {
				// Reload page to apply new template
				window.location.reload();
			})
			.catch(error => {
				console.error('Error saving template preference:', error);
				// Reload anyway
				window.location.reload();
			});
		});
	}

	// Language selector functionality
	function initLanguageSelector() {
		const languageForm = document.getElementById('languageForm');
		
		if (!languageForm) return;

		const radioButtons = languageForm.querySelectorAll('input[name="select_language"]');
		
		radioButtons.forEach(radio => {
			radio.addEventListener('change', function() {
				if (this.checked) {
					// Set cookie for selected language
					document.cookie = `selected_lang=${this.value}; path=/; max-age=31536000`;
					
					// Reload page to apply language
					setTimeout(() => {
						window.location.reload();
					}, 100);
				}
			});
		});
	}

	// Initialize all components when DOM is ready
	function init() {
		// Initialize all dropdowns
		document.querySelectorAll('.app-dropdown').forEach(element => {
			new Dropdown(element);
		});

		// Initialize sidebar toggle
		initSidebarToggle();

		// Initialize template selector
		initTemplateSelector();

		// Initialize language selector
		initLanguageSelector();

		// Handle responsive behavior
		handleResponsive();
		window.addEventListener('resize', handleResponsive);
	}

	// Responsive behavior
	function handleResponsive() {
		const sidebar = document.querySelector('.app-sidebar');
		const isMobile = window.innerWidth < 992;

		if (sidebar) {
			if (isMobile) {
				sidebar.classList.add('app-sidebar--collapsed');
			} else {
				// Restore saved state on desktop
				const savedState = localStorage.getItem('sidebar-collapsed');
				if (savedState !== 'true') {
					sidebar.classList.remove('app-sidebar--collapsed');
				}
			}
		}
	}

	// Bootstrap compatibility: Handle data-bs-toggle for modals, tabs, collapse
	function initBootstrapCompatibility() {
		// Modal triggers
		document.addEventListener('click', function(e) {
			const trigger = e.target.closest('[data-bs-toggle="modal"]');
			if (trigger) {
				e.preventDefault();
				const targetSelector = trigger.getAttribute('data-bs-target');
				if (targetSelector) {
					const modal = document.querySelector(targetSelector);
					if (modal && modal.tagName === 'DIALOG') {
						modal.showModal();
					}
				}
			}
		});

		// Modal dismiss
		document.addEventListener('click', function(e) {
			const dismissBtn = e.target.closest('[data-bs-dismiss="modal"]');
			if (dismissBtn) {
				const dialog = dismissBtn.closest('dialog');
				if (dialog) {
					dialog.close();
				}
			}
		});

		// Tab toggle
		document.addEventListener('click', function(e) {
			const tabTrigger = e.target.closest('[data-bs-toggle="tab"]');
			if (tabTrigger) {
				e.preventDefault();
				const targetSelector = tabTrigger.getAttribute('data-bs-target') || tabTrigger.getAttribute('href');
				if (targetSelector) {
					// Remove active from all tabs
					const tabContainer = tabTrigger.closest('[role="tablist"]') || tabTrigger.closest('ul') || tabTrigger.parentElement;
					if (tabContainer) {
						tabContainer.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
							tab.classList.remove('active');
						});
					}
					tabTrigger.classList.add('active');

					// Show target pane, hide others
					const targetPane = document.querySelector(targetSelector);
					if (targetPane) {
						const tabContent = targetPane.parentElement;
						// Find all tab panes - look for role="tabpanel" or .tab-pane class
						tabContent.querySelectorAll('[role="tabpanel"], .tab-pane').forEach(pane => {
							pane.classList.remove('show', 'active');
						});
						targetPane.classList.add('show', 'active');
					}
				}
			}
		});

		// Dropdown toggle (Bootstrap compatibility)
		document.addEventListener('click', function(e) {
			const dropdownTrigger = e.target.closest('[data-bs-toggle="dropdown"]');
			if (dropdownTrigger) {
				e.preventDefault();
				e.stopPropagation();
				const dropdown = dropdownTrigger.closest('.app-dropdown');
				if (dropdown) {
					const menu = dropdown.querySelector('.app-dropdown-menu');
					if (menu) {
						// Close all other dropdowns
						document.querySelectorAll('.app-dropdown.show').forEach(d => {
							if (d !== dropdown) {
								d.classList.remove('show');
							}
						});
						// Toggle current dropdown
						dropdown.classList.toggle('show');
						const isOpen = dropdown.classList.contains('show');
						dropdownTrigger.setAttribute('aria-expanded', isOpen);
					}
				}
			}
		});

		// Close dropdowns when clicking outside
		document.addEventListener('click', function(e) {
			if (!e.target.closest('.app-dropdown')) {
				document.querySelectorAll('.app-dropdown.show').forEach(dropdown => {
					dropdown.classList.remove('show');
					const trigger = dropdown.querySelector('[data-bs-toggle="dropdown"]');
					if (trigger) {
						trigger.setAttribute('aria-expanded', 'false');
					}
				});
			}
		});

		// Collapse toggle
		document.addEventListener('click', function(e) {
			const collapseTrigger = e.target.closest('[data-bs-toggle="collapse"]');
			if (collapseTrigger) {
				e.preventDefault();
				const targetSelector = collapseTrigger.getAttribute('data-bs-target') || collapseTrigger.getAttribute('href');
				if (targetSelector) {
					const targetElement = document.querySelector(targetSelector);
					if (targetElement) {
						targetElement.classList.toggle('show');
						const isExpanded = targetElement.classList.contains('show');
						collapseTrigger.setAttribute('aria-expanded', isExpanded);
					}
				}
			}
		});
	}

	// Wait for DOM to be ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Initialize Bootstrap compatibility
	initBootstrapCompatibility();

	// Export for use in other scripts if needed
	window.DigdirUI = {
		Dropdown: Dropdown,
		initSidebarToggle: initSidebarToggle
	};
})();
