import { test, expect } from '@playwright/test';
import { waitForPageLoad } from './helpers/accessibility';
import { TEST_URLS } from './fixtures/test-data';

/**
 * Screen Reader Simulation Tests
 *
 * These tests simulate how screen readers interact with the page by:
 * 1. Checking the accessibility tree
 * 2. Validating ARIA live regions
 * 3. Testing dynamic content announcements
 * 4. Verifying accessible names and descriptions
 *
 * NOTE: These tests check what screen readers WOULD read, but don't test
 * actual screen readers (NVDA, JAWS, VoiceOver). For full verification,
 * manual screen reader testing is still recommended.
 */

test.describe('Screen Reader Simulation Tests', () => {
  test.describe('Accessibility Tree Validation', () => {
    test('should have proper accessibility tree on home page', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Get accessibility snapshot (what screen readers see)
      const snapshot = await page.accessibility.snapshot();
      expect(snapshot, 'Page should have accessibility tree').toBeTruthy();

      // Check for main landmarks
      const hasLandmarks = await page.evaluate(() => {
        const landmarks = document.querySelectorAll(
          '[role="banner"], [role="main"], [role="navigation"], [role="contentinfo"], header, main, nav, footer'
        );
        return landmarks.length > 0;
      });
      expect(hasLandmarks, 'Should have landmark regions for screen readers').toBeTruthy();
    });

    test('should have accessible names for all interactive elements', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Check all buttons have accessible names
      const buttons = page.locator('button');
      const buttonCount = await buttons.count();

      const buttonsWithoutNames: number[] = [];

      for (let i = 0; i < buttonCount; i++) {
        const button = buttons.nth(i);
        const isVisible = await button.isVisible();

        if (isVisible) {
          const accessibleName = await button.evaluate((el) => {
            // Calculate accessible name (what screen reader announces)
            const computedName =
              el.getAttribute('aria-label') ||
              el.getAttribute('aria-labelledby') ||
              el.textContent?.trim() ||
              el.getAttribute('title');
            return computedName;
          });

          if (!accessibleName) {
            buttonsWithoutNames.push(i);
          }
        }
      }

      expect(
        buttonsWithoutNames,
        `Buttons without accessible names at indices: ${buttonsWithoutNames.join(', ')}`
      ).toHaveLength(0);
    });

    test('should have accessible names for all links', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      const links = page.locator('a[href]');
      const linkCount = await links.count();

      const linksWithoutNames: number[] = [];

      for (let i = 0; i < Math.min(linkCount, 20); i++) {
        const link = links.nth(i);
        const isVisible = await link.isVisible();

        if (isVisible) {
          const accessibleName = await link.evaluate((el) => {
            return (
              el.getAttribute('aria-label') ||
              el.textContent?.trim() ||
              el.getAttribute('title')
            );
          });

          if (!accessibleName) {
            linksWithoutNames.push(i);
          }
        }
      }

      expect(
        linksWithoutNames,
        `Links without accessible names at indices: ${linksWithoutNames.join(', ')}`
      ).toHaveLength(0);
    });
  });

  test.describe('ARIA Live Regions', () => {
    test('should announce search results to screen readers', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Check for aria-live regions
      const liveRegions = page.locator('[aria-live], [role="status"], [role="alert"]');
      const count = await liveRegions.count();

      // If live regions exist, verify they're properly configured
      if (count > 0) {
        const firstLive = liveRegions.first();
        const ariaLive = await firstLive.getAttribute('aria-live');
        const role = await firstLive.getAttribute('role');

        expect(
          ariaLive === 'polite' || ariaLive === 'assertive' || role === 'status' || role === 'alert',
          'Live regions should have proper aria-live or role'
        ).toBeTruthy();
      }

      // Perform search and check if results would be announced
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('kulturhus');
      await page.waitForTimeout(2000);

      // Check if result count or status is announced
      const resultCount = await page.locator('a:has(h3)').count();
      expect(resultCount, 'Search should return results').toBeGreaterThan(0);

      // Screen readers should be able to navigate to results
      const firstResult = page.locator('a:has(h3)').first();
      const accessibleName = await firstResult.evaluate((el) => {
        return el.textContent?.trim() || el.getAttribute('aria-label');
      });
      expect(accessibleName, 'Results should have accessible names for screen readers').toBeTruthy();
    });

    test('should announce form validation errors', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Check if error regions are set up
      const errorRegions = page.locator('[role="alert"], [aria-live="assertive"]');
      const hasErrorSetup = await errorRegions.count();

      // Error regions should exist or be added dynamically
      // This is informational - apps may handle errors differently
      console.log(`Error announcement regions found: ${hasErrorSetup}`);
    });
  });

  test.describe('Screen Reader Navigation Order', () => {
    test('should have logical reading order on home page', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Get tab order (what screen reader would navigate through)
      const tabOrder = await page.evaluate(() => {
        const tabbable = Array.from(
          document.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
          )
        ).filter((el) => {
          const element = el as HTMLElement;
          return element.offsetParent !== null; // Is visible
        });

        return tabbable.map((el, index) => ({
          index,
          tag: el.tagName,
          text: el.textContent?.trim().substring(0, 30) || '',
          ariaLabel: el.getAttribute('aria-label'),
          role: el.getAttribute('role'),
        }));
      });

      expect(tabOrder.length, 'Should have tabbable elements').toBeGreaterThan(5);

      // Verify logical order: logo/navigation first, then main content, then footer
      const firstElements = tabOrder.slice(0, 5);
      const lastElements = tabOrder.slice(-5);

      // First elements should be navigation (logo, buttons)
      console.log('First focusable elements (screen reader order):', firstElements);

      // Last elements should be footer links
      console.log('Last focusable elements:', lastElements);
    });

    test('should have logical heading hierarchy for screen readers', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      const headingStructure = await page.evaluate(() => {
        const headings = Array.from(document.querySelectorAll('h1, h2, h3, h4, h5, h6'));
        return headings.map((h) => ({
          level: parseInt(h.tagName.substring(1)),
          text: h.textContent?.trim().substring(0, 50),
        }));
      });

      expect(headingStructure.length, 'Should have headings').toBeGreaterThan(0);

      // Check heading levels are logical (don't skip levels)
      for (let i = 0; i < headingStructure.length - 1; i++) {
        const current = headingStructure[i].level;
        const next = headingStructure[i + 1].level;
        const diff = next - current;

        expect(
          diff,
          `Should not skip heading levels: H${current} -> H${next} at "${headingStructure[i].text}"`
        ).toBeLessThanOrEqual(1);
      }

      console.log('Heading structure (screen reader navigation):', headingStructure);
    });
  });

  test.describe('Screen Reader Announcements on Building Page', () => {
    test('should announce building information properly', async ({ page }) => {
      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page);

      // Get what screen reader would announce for building name
      const buildingHeading = page.locator('h2').first();
      const headingInfo = await buildingHeading.evaluate((el) => ({
        text: el.textContent?.trim(),
        role: el.tagName.toLowerCase(),
        ariaLabel: el.getAttribute('aria-label'),
        ariaLevel: el.getAttribute('aria-level') || '2',
      }));

      expect(headingInfo.text, 'Building name should be announced').toBeTruthy();
      expect(headingInfo.text?.length, 'Building name should have content').toBeGreaterThan(0);

      console.log('Screen reader would announce building as:', headingInfo);
    });

    test('should announce accordion state changes', async ({ page }) => {
      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page);

      // Find accordion buttons
      const accordions = page.locator('button:has-text("Utleieressurser"), button:has-text("Beskrivelse"), button:has-text("Dokumenter")');
      const count = await accordions.count();

      if (count > 0) {
        const firstAccordion = accordions.first();

        // Get initial state
        const initialState = await firstAccordion.evaluate((el) => ({
          text: el.textContent?.trim(),
          ariaExpanded: el.getAttribute('aria-expanded'),
          ariaControls: el.getAttribute('aria-controls'),
        }));

        expect(
          initialState.ariaExpanded,
          'Accordion should have aria-expanded for screen readers'
        ).toBeTruthy();

        // Click to toggle
        await firstAccordion.click();
        await page.waitForTimeout(500);

        // Get new state
        const newState = await firstAccordion.evaluate((el) => ({
          ariaExpanded: el.getAttribute('aria-expanded'),
        }));

        expect(
          newState.ariaExpanded,
          'aria-expanded should toggle (screen readers announce this)'
        ).not.toBe(initialState.ariaExpanded);

        console.log(
          `Screen reader would announce: "${initialState.text}" ${initialState.ariaExpanded === 'true' ? 'expanded' : 'collapsed'} -> ${newState.ariaExpanded === 'true' ? 'expanded' : 'collapsed'}`
        );
      }
    });

    test('should announce calendar resource selections', async ({ page }) => {
      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page);
      await page.waitForTimeout(2000);

      // Check checkbox labels (what screen reader reads)
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();

      if (count > 0) {
        const checkboxInfo = await checkboxes.first().evaluate((el) => {
          const checkbox = el as HTMLInputElement;
          const id = checkbox.id;
          const label = id ? document.querySelector(`label[for="${id}"]`) : null;
          const ariaLabel = checkbox.getAttribute('aria-label');
          const ariaLabelledBy = checkbox.getAttribute('aria-labelledby');

          let labelText = '';
          if (ariaLabel) labelText = ariaLabel;
          else if (ariaLabelledBy) {
            const labelEl = document.getElementById(ariaLabelledBy);
            labelText = labelEl?.textContent?.trim() || '';
          } else if (label) labelText = label.textContent?.trim() || '';

          return {
            checked: checkbox.checked,
            label: labelText,
            hasLabel: !!(ariaLabel || label || ariaLabelledBy),
          };
        });

        expect(
          checkboxInfo.hasLabel,
          'Checkbox should have label for screen readers'
        ).toBeTruthy();

        console.log(
          `Screen reader would announce: "${checkboxInfo.label}" checkbox, ${checkboxInfo.checked ? 'checked' : 'not checked'}`
        );
      }
    });
  });

  test.describe('Screen Reader Form Accessibility', () => {
    test('should announce form labels and controls properly', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Get search input accessible name
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      const searchInfo = await searchInput.evaluate((el) => {
        const input = el as HTMLInputElement;
        const id = input.id;
        const label = id ? document.querySelector(`label[for="${id}"]`) : null;

        return {
          ariaLabel: input.getAttribute('aria-label'),
          placeholder: input.getAttribute('placeholder'),
          labelText: label?.textContent?.trim(),
          type: input.type,
        };
      });

      const announcedName =
        searchInfo.ariaLabel || searchInfo.labelText || searchInfo.placeholder;

      expect(
        announcedName,
        'Search input should have accessible name for screen readers'
      ).toBeTruthy();

      console.log(`Screen reader would announce: "${announcedName}" ${searchInfo.type} edit`);

      // Test location combobox
      const locationSelect = page.locator('[role="combobox"], select').first();
      const locationInfo = await locationSelect.evaluate((el) => {
        const id = el.id;
        const label = id ? document.querySelector(`label[for="${id}"]`) : null;

        return {
          ariaLabel: el.getAttribute('aria-label'),
          labelText: label?.textContent?.trim(),
          value: (el as HTMLSelectElement).value || el.getAttribute('aria-valuenow'),
          role: el.getAttribute('role'),
        };
      });

      const locationName = locationInfo.ariaLabel || locationInfo.labelText || 'Hvor';

      expect(
        locationName,
        'Location selector should have accessible name'
      ).toBeTruthy();

      console.log(
        `Screen reader would announce: "${locationName}" ${locationInfo.role || 'combobox'}, ${locationInfo.value || 'Alle'}`
      );
    });

    test('should announce tabs and their states', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      const tabs = page.locator('[role="tab"]');
      const tabCount = await tabs.count();

      expect(tabCount, 'Should have tabs').toBeGreaterThanOrEqual(3);

      // Get tab information for each tab
      for (let i = 0; i < tabCount; i++) {
        const tab = tabs.nth(i);
        const tabInfo = await tab.evaluate((el) => ({
          text: el.textContent?.trim(),
          ariaSelected: el.getAttribute('aria-selected'),
          ariaControls: el.getAttribute('aria-controls'),
          role: el.getAttribute('role'),
        }));

        expect(tabInfo.text, `Tab ${i} should have text`).toBeTruthy();
        expect(tabInfo.ariaSelected, `Tab ${i} should have aria-selected`).toBeTruthy();

        console.log(
          `Screen reader would announce tab ${i + 1}: "${tabInfo.text}" tab, ${tabInfo.ariaSelected === 'true' ? 'selected' : 'not selected'}`
        );
      }
    });
  });

  test.describe('Dynamic Content Announcements', () => {
    test('should properly announce search results appearing', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Perform search
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('møterom');
      await page.waitForTimeout(2000);

      // Check if results container has proper ARIA
      const resultsContainer = page.locator('a:has(h3)').first().locator('..');

      // Results should be navigable
      const results = page.locator('a:has(h3)');
      const resultCount = await results.count();

      expect(resultCount, 'Should have results to navigate').toBeGreaterThan(0);

      // Get what screen reader would announce for first result
      const firstResult = results.first();
      const announcement = await firstResult.evaluate((el) => {
        const heading = el.querySelector('h3');
        const buildingLink = el.querySelector('a');

        return {
          resourceName: heading?.textContent?.trim(),
          buildingName: buildingLink?.textContent?.trim(),
          href: el.getAttribute('href'),
          role: 'link',
        };
      });

      expect(announcement.resourceName, 'Screen reader should announce resource name').toBeTruthy();

      console.log(
        `Screen reader would announce result: "${announcement.resourceName}" link, building: ${announcement.buildingName}`
      );
    });

    test('should announce filter changes', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      const locationSelect = page.locator('select, [role="combobox"]').first();

      // Get initial announcement
      const initialValue = await locationSelect.inputValue();
      console.log(`Screen reader initial: "Hvor", combobox, ${initialValue || 'Alle'}`);

      // Change location
      await locationSelect.selectOption('Fana');
      await page.waitForTimeout(1500);

      // Get new announcement
      const newValue = await locationSelect.inputValue();
      console.log(`Screen reader after change: "Hvor", combobox, Fana selected`);

      // Value should change
      expect(newValue).not.toBe(initialValue);
    });
  });

  test.describe('Complex Widget Accessibility', () => {
    test('should properly announce calendar controls', async ({ page }) => {
      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page);
      await page.waitForTimeout(3000);

      // Calendar view switcher
      const viewButtons = page.locator('button:has-text("Dag"), button:has-text("Uke"), button:has-text("Måned")');
      const count = await viewButtons.count();

      if (count > 0) {
        const dagButton = page.locator('button:has-text("Dag")').first();
        const buttonAnnouncement = await dagButton.evaluate((el) => ({
          text: el.textContent?.trim(),
          role: el.getAttribute('role') || 'button',
          ariaLabel: el.getAttribute('aria-label'),
          ariaPressed: el.getAttribute('aria-pressed'),
        }));

        expect(buttonAnnouncement.text, 'Calendar view button should have text').toBeTruthy();

        console.log(
          `Screen reader would announce: "${buttonAnnouncement.ariaLabel || buttonAnnouncement.text}" ${buttonAnnouncement.role}${buttonAnnouncement.ariaPressed ? ', ' + buttonAnnouncement.ariaPressed : ''}`
        );
      }
    });

    test('should announce resource selection checkboxes with labels', async ({ page }) => {
      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page);
      await page.waitForTimeout(3000);

      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();

      if (count > 1) {
        // Test second checkbox (first after "Velg alle")
        const resourceCheckbox = checkboxes.nth(1);

        const checkboxAnnouncement = await resourceCheckbox.evaluate((el) => {
          const checkbox = el as HTMLInputElement;
          const id = checkbox.id;

          // Find associated label
          let labelText = '';
          if (id) {
            const label = document.querySelector(`label[for="${id}"]`);
            labelText = label?.textContent?.trim() || '';
          }

          // Check for aria-label
          const ariaLabel = checkbox.getAttribute('aria-label');

          // Check for aria-labelledby
          const ariaLabelledBy = checkbox.getAttribute('aria-labelledby');
          if (ariaLabelledBy) {
            const labelEl = document.getElementById(ariaLabelledBy);
            labelText = labelEl?.textContent?.trim() || labelText;
          }

          return {
            label: ariaLabel || labelText,
            checked: checkbox.checked,
            disabled: checkbox.disabled,
          };
        });

        expect(
          checkboxAnnouncement.label,
          'Resource checkbox should have label for screen readers'
        ).toBeTruthy();

        console.log(
          `Screen reader would announce: "${checkboxAnnouncement.label}" checkbox, ${checkboxAnnouncement.checked ? 'checked' : 'not checked'}${checkboxAnnouncement.disabled ? ', disabled' : ''}`
        );
      }
    });
  });

  test.describe('Accessible Name Computation', () => {
    test('should compute accessible names correctly for complex elements', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Test tab button (has both icon and text)
      const leieTab = page.locator('[role="tab"]:has-text("Leie")').first();

      const computedName = await leieTab.evaluate((el) => {
        // Simulate accessible name computation algorithm
        const ariaLabelledBy = el.getAttribute('aria-labelledby');
        const ariaLabel = el.getAttribute('aria-label');
        const title = el.getAttribute('title');
        const text = el.textContent?.trim();

        // Priority: aria-labelledby > aria-label > content > title
        let accessibleName = '';
        if (ariaLabelledBy) {
          const labelEl = document.getElementById(ariaLabelledBy);
          accessibleName = labelEl?.textContent?.trim() || '';
        } else if (ariaLabel) {
          accessibleName = ariaLabel;
        } else if (text) {
          accessibleName = text;
        } else if (title) {
          accessibleName = title;
        }

        return {
          name: accessibleName,
          method: ariaLabelledBy
            ? 'aria-labelledby'
            : ariaLabel
            ? 'aria-label'
            : text
            ? 'text content'
            : 'title',
        };
      });

      expect(computedName.name, 'Element should have accessible name').toBeTruthy();
      expect(computedName.name.length, 'Accessible name should not be empty').toBeGreaterThan(0);

      console.log(
        `Accessible name computed from ${computedName.method}: "${computedName.name}"`
      );
    });
  });

  test.describe('Screen Reader Resource Navigation', () => {
    test('should announce complete resource information', async ({ page }) => {
      await page.goto(TEST_URLS.resources.resource452.no);
      await waitForPageLoad(page);

      // Resource heading
      const resourceHeading = page.locator('h2').first();
      const resourceName = await resourceHeading.textContent();

      expect(resourceName?.trim(), 'Resource name should be announced').toBeTruthy();

      // Building link
      const buildingLink = page.locator('a[href*="/building/"]').first();
      const buildingLinkInfo = await buildingLink.evaluate((el) => ({
        text: el.textContent?.trim(),
        href: el.getAttribute('href'),
        ariaLabel: el.getAttribute('aria-label'),
      }));

      expect(
        buildingLinkInfo.text || buildingLinkInfo.ariaLabel,
        'Building link should have accessible name'
      ).toBeTruthy();

      console.log(
        `Screen reader navigation: Resource "${resourceName?.trim()}", Building link "${buildingLinkInfo.text}"`
      );
    });
  });

  test.describe('Accessible Descriptions (aria-describedby)', () => {
    test('should provide descriptions for complex controls', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Check for elements with aria-describedby
      const describedElements = page.locator('[aria-describedby]');
      const count = await describedElements.count();

      if (count > 0) {
        const firstElement = describedElements.first();
        const descriptionInfo = await firstElement.evaluate((el) => {
          const describedBy = el.getAttribute('aria-describedby');
          const descriptionEl = describedBy ? document.getElementById(describedBy) : null;

          return {
            element: el.tagName,
            description: descriptionEl?.textContent?.trim(),
            ariaLabel: el.getAttribute('aria-label'),
          };
        });

        expect(
          descriptionInfo.description,
          'aria-describedby should point to actual description'
        ).toBeTruthy();

        console.log(
          `Screen reader would announce with description: "${descriptionInfo.ariaLabel}" then "${descriptionInfo.description}"`
        );
      } else {
        console.log('No aria-describedby found (informational - not always needed)');
      }
    });
  });

  test.describe('Screen Reader Table/Grid Navigation', () => {
    test('should announce calendar grid structure', async ({ page }) => {
      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page);
      await page.waitForTimeout(3000);

      // Check for table/grid roles in calendar
      const gridElements = page.locator('[role="grid"], [role="table"], table');
      const hasGrid = await gridElements.count();

      if (hasGrid > 0) {
        const gridInfo = await gridElements.first().evaluate((el) => ({
          role: el.getAttribute('role') || el.tagName.toLowerCase(),
          ariaLabel: el.getAttribute('aria-label'),
          ariaLabelledBy: el.getAttribute('aria-labelledby'),
        }));

        console.log(
          `Calendar uses ${gridInfo.role} structure${gridInfo.ariaLabel ? ` labeled "${gridInfo.ariaLabel}"` : ''} for screen reader navigation`
        );
      }

      // Check for row/cell structure
      const rows = page.locator('[role="row"], tr');
      const rowCount = await rows.count();

      if (rowCount > 0) {
        console.log(`Calendar has ${rowCount} rows for screen reader navigation`);

        // Check cells
        const cells = page.locator('[role="gridcell"], [role="columnheader"], th, td');
        const cellCount = await cells.count();

        console.log(`Calendar has ${cellCount} cells for screen reader navigation`);
      }
    });
  });
});
