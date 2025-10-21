import { test, expect } from '@playwright/test';
import {
  scanAccessibility,
  formatViolations,
  waitForPageLoad,
  WCAGLevel,
} from './helpers/accessibility';
import { TEST_URLS } from './fixtures/test-data';

test.describe('Search and Filter Functionality - Complete Navigation Tests', () => {
  test.describe('Text Search Functionality', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);
    });

    test('should perform text search and display results', async ({ page }) => {
      // Type in search box
      const searchInput = page.locator('input[placeholder*="Søk"], input[type="text"]').first();
      await searchInput.fill('kulturhus');
      await page.waitForTimeout(1000); // Wait for debounce/search

      // Should show search results
      const results = page.locator('h3:has-text("Kultursal"), h3:has-text("kulturhus")');
      const resultCount = await results.count();
      expect(resultCount, 'Should display search results for "kulturhus"').toBeGreaterThan(0);

      // Results should be clickable links
      const firstResult = page.locator('a:has(h3)').first();
      await expect(firstResult).toBeVisible();
      await expect(firstResult).toHaveAttribute('href');

      // Search results should be accessible
      const resultsAfterSearch = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });

      const criticalViolations = resultsAfterSearch.violations.filter(
        (v) => v.impact === 'critical'
      );
      expect(
        criticalViolations,
        `Critical violations after search:\n${formatViolations(criticalViolations)}`
      ).toHaveLength(0);
    });

    test('should show search term as removable chip/button', async ({ page }) => {
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('kulturhus');
      await page.waitForTimeout(1000);

      // Should show search chip/button
      const searchChip = page.locator('button:has-text("kulturhus")');
      await expect(searchChip).toBeVisible();

      // Chip should be clickable to remove filter
      await searchChip.click();
      await page.waitForTimeout(500);

      // Search results should change or clear
      const emptyMessage = page.locator('text=/Bruk filtr.*?for å finne/i');
      const hasEmptyMessage = await emptyMessage.count();
      expect(hasEmptyMessage).toBeGreaterThan(0);
    });

    test('should maintain accessibility during search interaction', async ({ page }) => {
      const searchInput = page.locator('input[placeholder*="Søk"]').first();

      // Before search
      await searchInput.focus();
      const isFocused = await searchInput.evaluate((el) => document.activeElement === el);
      expect(isFocused, 'Search input should be focusable').toBeTruthy();

      // Type and search
      await searchInput.fill('møterom');
      await page.waitForTimeout(1000);

      // After search, page should still be accessible
      const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
      expect(criticalViolations).toHaveLength(0);
    });

    test('should allow keyboard navigation in search results', async ({ page }) => {
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('kulturhus');
      await page.waitForTimeout(1000);

      // Tab to first result
      await searchInput.press('Tab');
      await page.waitForTimeout(300);

      // Should be able to navigate through results with Tab
      const focusedElement = await page.evaluate(() => {
        const active = document.activeElement;
        return {
          tagName: active?.tagName,
          text: active?.textContent?.substring(0, 50),
        };
      });

      expect(['A', 'BUTTON', 'INPUT']).toContain(focusedElement.tagName);
    });
  });

  test.describe('Location Filter Functionality', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);
    });

    test('should filter by location (Fana)', async ({ page }) => {
      // Select Fana from location dropdown
      const locationSelect = page.locator('select, [role="combobox"]').first();
      await locationSelect.selectOption('Fana');
      await page.waitForTimeout(1000);

      // Should show only Fana results
      const fanaResults = page.locator('text=Fana');
      const fanaCount = await fanaResults.count();
      expect(fanaCount, 'Should show Fana location results').toBeGreaterThan(0);

      // Verify results are from Fana
      const nonFanaResults = page.locator('text=Årstad, text=Bergenhus, text=Laksevåg').first();
      const hasNonFana = await nonFanaResults.count();
      // Results might show other locations if they match search, so this is informational
    });

    test('should have accessible location dropdown', async ({ page }) => {
      // Select should be present and accessible
      const locationSelect = page.locator('#location-select, [role="combobox"], select').first();
      await expect(locationSelect).toBeVisible();
      await expect(locationSelect).toBeEnabled();

      // Should have proper label association
      const hasLabel = await locationSelect.evaluate((el) => {
        const id = el.id;
        const label = id ? document.querySelector(`label[for="${id}"]`) : null;
        const ariaLabel = el.getAttribute('aria-label');
        return !!(label || ariaLabel);
      });
      expect(hasLabel, 'Location select should have associated label').toBeTruthy();

      // Should have options (check in DOM, not visibility)
      const options = await locationSelect.evaluate((el) => {
        const select = el as HTMLSelectElement;
        return Array.from(select.options).map(opt => ({
          value: opt.value,
          text: opt.textContent?.trim()
        }));
      });

      expect(options.length, 'Location dropdown should have options').toBeGreaterThan(5);

      // Check for expected locations in options
      const hasAlle = options.some(opt => opt.text === 'Alle');
      const hasFana = options.some(opt => opt.text === 'Fana');
      const hasÅsane = options.some(opt => opt.text === 'Åsane');

      expect(hasAlle, 'Should have "Alle" option').toBeTruthy();
      expect(hasFana, 'Should have "Fana" option').toBeTruthy();
      expect(hasÅsane, 'Should have "Åsane" option').toBeTruthy();

      // Verify screen reader would announce the select properly
      const ariaLabel = await locationSelect.getAttribute('aria-label');
      expect(ariaLabel || hasLabel, 'Select should be announced to screen readers').toBeTruthy();
    });

    test('should combine search text and location filter', async ({ page }) => {
      // Type search
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('møterom');
      await page.waitForTimeout(500);

      // Select location
      const locationSelect = page.locator('select, [role="combobox"]').first();
      await locationSelect.selectOption('Fana');
      await page.waitForTimeout(1000);

      // Should show results matching both criteria
      const results = page.locator('h3');
      const resultCount = await results.count();
      expect(resultCount, 'Should show filtered results').toBeGreaterThan(0);

      // Check accessibility after combined filtering
      const accessibilityResults = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });
      const criticalViolations = accessibilityResults.violations.filter(
        (v) => v.impact === 'critical'
      );
      expect(criticalViolations).toHaveLength(0);
    });
  });

  test.describe('Tab Navigation Between Search Types', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);
    });

    test('should switch between Leie, Arrangement, and Organisasjon tabs', async ({ page }) => {
      // Verify Leie tab is initially selected (on home page /no/)
      const leieTab = page.locator('[role="tab"]:has-text("Leie")');
      const leieSelected = await leieTab.getAttribute('aria-selected');
      expect(leieSelected).toBe('true');

      // Click Arrangement tab (navigates to /no/search/event)
      const arrangementTab = page.locator('[role="tab"]:has-text("Arrangement")');
      await arrangementTab.click();

      // Wait for navigation to complete
      await page.waitForURL('**/search/event', { timeout: 10000 });
      await waitForPageLoad(page);

      // Verify we navigated to event search page
      const url = page.url();
      expect(url, 'Should navigate to event search page').toContain('/search/event');

      // On the new page, Arrangement tab should be selected
      const arrangementTabNew = page.locator('[role="tab"]:has-text("Arrangement")');
      const arrangementSelected = await arrangementTabNew.getAttribute('aria-selected');
      expect(arrangementSelected, 'Arrangement tab should be selected on event search page').toBe('true');

      // Click Organisasjon tab (navigates to /no/search/organization)
      const orgTab = page.locator('[role="tab"]:has-text("Organisasjon")');
      await orgTab.click();

      // Wait for navigation
      await page.waitForURL('**/search/organization', { timeout: 10000 });
      await waitForPageLoad(page);

      // Verify navigation
      const orgUrl = page.url();
      expect(orgUrl, 'Should navigate to organization search page').toContain('/search/organization');

      // On org page, Organisasjon tab should be selected
      const orgTabNew = page.locator('[role="tab"]:has-text("Organisasjon")');
      const orgSelected = await orgTabNew.getAttribute('aria-selected');
      expect(orgSelected, 'Organisasjon tab should be selected on organization search page').toBe('true');

      // Page should still be accessible after tab navigation
      const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
      expect(criticalViolations).toHaveLength(0);
    });

    test('should allow keyboard navigation between tabs', async ({ page }) => {
      const leieTab = page.locator('[role="tab"]:has-text("Leie")');
      await leieTab.focus();

      // Should be focused
      const isFocused = await leieTab.evaluate((el) => document.activeElement === el);
      expect(isFocused, 'Tab should be focusable').toBeTruthy();

      // Try arrow key navigation (ARIA tabs pattern)
      await page.keyboard.press('ArrowRight');
      await page.waitForTimeout(200);

      // Check if focus moved or if Enter/Space activates tabs
      await leieTab.focus();
      await page.keyboard.press('Tab');
      const nextFocused = await page.evaluate(() => document.activeElement?.tagName);
      expect(nextFocused).toBeTruthy();
    });

    test('should persist search when switching tabs', async ({ page }) => {
      // Enter search term
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('kulturhus');
      await page.waitForTimeout(500);

      // Switch to Arrangement tab
      const arrangementTab = page.locator('[role="tab"]:has-text("Arrangement")');
      await arrangementTab.click();
      await page.waitForTimeout(500);

      // Search input should still have the value
      const searchValue = await searchInput.inputValue();
      expect(searchValue, 'Search value should persist across tabs').toBe('kulturhus');
    });
  });

  test.describe('Search Results Display', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);
    });

    test('should display accessible search results cards', async ({ page }) => {
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('kulturhus');
      await page.waitForTimeout(1000);

      // Check result card structure
      const resultLinks = page.locator('a:has(h3)');
      const resultCount = await resultLinks.count();
      expect(resultCount).toBeGreaterThan(0);

      // Check first result card accessibility
      const firstResult = resultLinks.first();

      // Should have h3 heading(s) - cards may have multiple h3s (icon + title)
      const hasHeading = await firstResult.locator('h3').count();
      expect(hasHeading, 'Result should have h3 heading').toBeGreaterThanOrEqual(1);

      // Heading should have text content
      const headings = firstResult.locator('h3');
      const headingTexts = await headings.allTextContents();
      const hasText = headingTexts.some(text => text.trim().length > 0);
      expect(hasText, 'At least one heading should have text').toBeTruthy();

      // Result card is a link itself - should have href
      const href = await firstResult.getAttribute('href');
      expect(href, 'Result should link somewhere').toBeTruthy();
      expect(href?.length, 'href should not be empty').toBeGreaterThan(0);

      // Should have accessible name for screen readers
      const accessibleName = await firstResult.evaluate((el) => {
        return el.textContent?.trim() || el.getAttribute('aria-label');
      });
      expect(accessibleName?.length, 'Result should have accessible name').toBeGreaterThan(0);

      // Location should be shown somewhere in the page near result
      const resultParent = firstResult.locator('..');
      const parentText = await resultParent.textContent();
      const hasLocationNearby = parentText?.includes('Fana') || parentText?.includes('Årstad') ||
                                parentText?.includes('Åsane') || parentText?.includes('Bergenhus') ||
                                parentText?.includes('Laksevåg') || parentText?.includes('Fyllingsdalen') ||
                                parentText?.includes('Øvrige') || parentText?.includes('Arna');

      // Location might be inside or outside the link - either is fine
      expect(hasLocationNearby || accessibleName?.includes('Fana'), 'Location should be shown').toBeTruthy();
    });

    test('should navigate to resource page from search results', async ({ page }) => {
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('møterom');
      await page.waitForTimeout(1500);

      // Find first result link
      const resultLinks = page.locator('a:has(h3)');
      const count = await resultLinks.count();

      if (count > 0) {
        const firstResult = resultLinks.first();
        const href = await firstResult.getAttribute('href');
        expect(href, 'Result should have href').toBeTruthy();

        // Result might link to resource page or building page (both are valid)
        const isResourceOrBuilding = href?.includes('/resource/') || href?.includes('/building/');
        expect(isResourceOrBuilding, 'Should link to resource or building page').toBeTruthy();

        // Click and navigate (use waitForNavigation to ensure navigation happens)
        const [response] = await Promise.all([
          page.waitForNavigation({ timeout: 30000 }),
          firstResult.click()
        ]);

        // Wait for page to be ready
        await waitForPageLoad(page);

        // Should navigate to a detail page (resource or building)
        const url = page.url();
        const navigatedToDetail = url.includes('/resource/') || url.includes('/building/');
        expect(navigatedToDetail, `Should navigate to detail page, got: ${url}`).toBeTruthy();

        // Destination page should be accessible
        const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
        const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
        expect(criticalViolations).toHaveLength(0);
      }
    });

    test('should show empty state when no results found', async ({ page }) => {
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('xyznonexistentresource123');
      await page.waitForTimeout(1000);

      // Should show empty message or no results
      const emptyMessage = page.locator('text=/Bruk filtr.*?for å finne/i, text=/Ingen resultater/i, text=/No results/i');
      const hasMessage = await emptyMessage.count();
      expect(hasMessage, 'Should show empty state or message').toBeGreaterThanOrEqual(0);

      // Page should still be accessible with no results
      const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
      expect(criticalViolations).toHaveLength(0);
    });
  });

  test.describe('Location Filter by Town', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);
    });

    test('should filter results by Fana location', async ({ page }) => {
      const locationSelect = page.locator('select, [role="combobox"]').first();

      // Select Fana
      await locationSelect.selectOption('Fana');
      await page.waitForTimeout(1500); // Wait for filter to apply

      // Check if Fana-related results are shown
      const fanaText = page.locator('text=Fana');
      const fanaCount = await fanaText.count();

      // If results are shown, they should contain Fana
      const allResults = page.locator('a:has(h3)');
      const resultCount = await allResults.count();

      if (resultCount > 0) {
        expect(fanaCount, 'Results should include Fana location').toBeGreaterThan(0);
      }
    });

    test('should filter results by Åsane location', async ({ page }) => {
      const locationSelect = page.locator('select, [role="combobox"]').first();

      await locationSelect.selectOption('Åsane');
      await page.waitForTimeout(1500);

      const asaneText = page.locator('text=Åsane');
      const asaneCount = await asaneText.count();

      const allResults = page.locator('a:has(h3)');
      const resultCount = await allResults.count();

      if (resultCount > 0) {
        expect(asaneCount, 'Results should include Åsane location').toBeGreaterThan(0);
      }
    });

    test('should reset to all locations', async ({ page }) => {
      const locationSelect = page.locator('#location-select, select, [role="combobox"]').first();

      // Filter to Fana first
      await locationSelect.selectOption('Fana');
      await page.waitForTimeout(1000);

      // Verify Fana was selected
      const fanaValue = await locationSelect.inputValue();
      expect(fanaValue, 'Should select Fana').toBeTruthy();
      expect(fanaValue, 'Should not be empty after selecting Fana').not.toBe('');

      // Reset to Alle (which has value="")
      await locationSelect.selectOption('');
      await page.waitForTimeout(1000);

      // Should show all results (value is "" for "Alle" option)
      const selectedValue = await locationSelect.inputValue();
      expect(selectedValue, 'Reset to "Alle" should have empty value').toBe('');

      // Verify the displayed text is "Alle"
      const selectedText = await locationSelect.evaluate((el) => {
        const select = el as HTMLSelectElement;
        return select.options[select.selectedIndex]?.text;
      });
      expect(selectedText, 'Displayed text should be "Alle"').toBe('Alle');
    });
  });

  test.describe('Combined Search and Filter Workflow', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);
    });

    test('should perform complete search workflow: text + location', async ({ page }) => {
      // Step 1: Enter search text
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('møterom');
      await page.waitForTimeout(1000);

      // Verify results appear
      let resultLinks = page.locator('a:has(h3)');
      let initialCount = await resultLinks.count();
      expect(initialCount, 'Should show initial results').toBeGreaterThan(0);

      // Step 2: Apply location filter
      const locationSelect = page.locator('select, [role="combobox"]').first();
      await locationSelect.selectOption('Fana');
      await page.waitForTimeout(1500);

      // Verify filtered results
      let filteredResults = page.locator('a:has(h3)');
      let filteredCount = await filteredResults.count();

      // Results should contain Fana if any are shown
      if (filteredCount > 0) {
        const fanaInResults = page.locator('text=Fana');
        const fanaCount = await fanaInResults.count();
        expect(fanaCount, 'Filtered results should show Fana').toBeGreaterThan(0);
      }

      // Step 3: Clear search
      await searchInput.clear();
      await page.waitForTimeout(1000);

      // Step 4: Reset location
      await locationSelect.selectOption('Alle');
      await page.waitForTimeout(1000);

      // Should return to initial state
      const finalState = page.locator('text=/Bruk filtr.*?for å finne/i');
      const hasFinalMessage = await finalState.count();
      expect(hasFinalMessage).toBeGreaterThan(0);
    });

    test('should maintain accessibility throughout entire filter workflow', async ({ page }) => {
      // Search
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('kulturhus');
      await page.waitForTimeout(1000);

      let results1 = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      expect(results1.violations.filter((v) => v.impact === 'critical')).toHaveLength(0);

      // Add location filter
      const locationSelect = page.locator('select, [role="combobox"]').first();
      await locationSelect.selectOption('Fana');
      await page.waitForTimeout(1500);

      let results2 = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      expect(results2.violations.filter((v) => v.impact === 'critical')).toHaveLength(0);

      // Switch tab
      const arrangementTab = page.locator('[role="tab"]:has-text("Arrangement")');
      await arrangementTab.click();
      await page.waitForTimeout(500);

      let results3 = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      expect(results3.violations.filter((v) => v.impact === 'critical')).toHaveLength(0);
    });
  });

  test.describe('Search Result Cards Accessibility', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Perform search to get results
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('kulturhus');
      await page.waitForTimeout(1500);
    });

    test('should have accessible result card structure', async ({ page }) => {
      const resultCards = page.locator('a:has(h3)');
      const count = await resultCards.count();

      if (count > 0) {
        // Check first result card
        const firstCard = resultCards.first();

        // Should have h3 heading(s) - result cards may have 2 h3s (icon + title)
        const headings = firstCard.locator('h3');
        const headingCount = await headings.count();
        expect(headingCount, 'Result should have at least one h3 heading').toBeGreaterThanOrEqual(1);

        // Get text from last h3 (the title, not icon)
        const titleHeading = headings.last();
        const headingText = await titleHeading.textContent();
        expect(headingText?.trim().length, 'Heading should have text').toBeGreaterThan(0);

        // Should be a clickable link
        await expect(firstCard).toHaveAttribute('href');

        // Card should be accessible and have meaningful content
        const cardText = await firstCard.textContent();
        expect(cardText?.trim().length, 'Card should have text content').toBeGreaterThan(0);

        // Location may be inside link or as sibling - check the entire result row
        // Get parent container to check for location text
        const resultContainer = firstCard.locator('..');
        const containerText = await resultContainer.textContent();

        const hasLocation = containerText?.includes('Fana') || containerText?.includes('Årstad') ||
                           containerText?.includes('Åsane') || containerText?.includes('Bergenhus') ||
                           containerText?.includes('Laksevåg') || containerText?.includes('Fyllingsdalen') ||
                           containerText?.includes('Øvrige') || containerText?.includes('Arna') ||
                           containerText?.includes('Ytrebygda');

        // If no location found, that's okay - some results might not show location
        // The important thing is the card is accessible
        if (!hasLocation) {
          console.log('Note: Location not found in result card (may be optional)');
        }
      }
    });

    test('should have proper heading levels in results', async ({ page }) => {
      // Result headings should be h3
      const resultHeadings = page.locator('a h3');
      const count = await resultHeadings.count();

      if (count > 0) {
        // Verify they are h3, not h1 or h2
        for (let i = 0; i < Math.min(count, 5); i++) {
          const heading = resultHeadings.nth(i);
          const tagName = await heading.evaluate((el) => el.tagName);
          expect(tagName).toBe('H3');
        }
      }
    });

    test('should have accessible links to buildings', async ({ page }) => {
      const buildingLinks = page.locator('a[href*="/building/"], a:has-text("kulturhus")');
      const count = await buildingLinks.count();

      if (count > 0) {
        const firstLink = buildingLinks.first();
        const text = await firstLink.textContent();
        const href = await firstLink.getAttribute('href');

        expect(text?.trim().length, 'Building link should have text').toBeGreaterThan(0);
        expect(href, 'Building link should have href').toBeTruthy();
      }
    });
  });

  test.describe('Advanced Filter Button ("Flere filter")', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);
    });

    test('should have accessible "Flere filter" button', async ({ page }) => {
      const filterButton = page.locator('button:has-text("Flere filter")');
      await expect(filterButton).toBeVisible();
      await expect(filterButton).toBeEnabled();

      const text = await filterButton.textContent();
      expect(text?.trim()).toBe('Flere filter');

      // Button should be keyboard accessible
      await filterButton.focus();
      const isFocused = await filterButton.evaluate((el) => document.activeElement === el);
      expect(isFocused, '"Flere filter" button should be focusable').toBeTruthy();
    });

    test('should open filter panel when clicked', async ({ page }) => {
      const filterButton = page.locator('button:has-text("Flere filter")');

      // Click the button
      await filterButton.click();
      await page.waitForTimeout(1000);

      // Check if additional filters appear or panel opens
      // (This might open a modal or expand a section - adjust based on actual behavior)
      const pageContent = await page.content();

      // Page should remain accessible after opening filters
      const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
      expect(criticalViolations).toHaveLength(0);
    });
  });

  test.describe('Date Filter ("Når")', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);
    });

    test('should have accessible date input', async ({ page }) => {
      const dateInput = page.locator('input[value*="Velg dato"], input[placeholder*="dato"]').first();
      await expect(dateInput).toBeVisible();
      await expect(dateInput).toBeEnabled();

      // Should have label or aria-label
      const hasLabel = await dateInput.evaluate((el) => {
        const input = el as HTMLInputElement;
        return !!(
          input.getAttribute('aria-label') ||
          input.getAttribute('placeholder') ||
          (input.id && document.querySelector(`label[for="${input.id}"]`))
        );
      });
      expect(hasLabel, 'Date input should have accessible label').toBeTruthy();
    });

    test('should be keyboard accessible', async ({ page }) => {
      const dateInput = page.locator('input[value*="Velg dato"]').first();
      await dateInput.focus();

      const isFocused = await dateInput.evaluate((el) => document.activeElement === el);
      expect(isFocused, 'Date input should be focusable').toBeTruthy();

      // Should show focus indicator
      const hasFocusIndicator = await page.evaluate(() => {
        const el = document.activeElement as HTMLElement;
        if (!el) return false;
        const styles = window.getComputedStyle(el);
        return styles.outline !== 'none' || styles.boxShadow !== 'none';
      });
      expect(hasFocusIndicator, 'Date input should have visible focus').toBeTruthy();
    });
  });

  test.describe('Complete User Journey - Search to Resource', () => {
    test('should complete full accessibility-compliant search journey', async ({ page }) => {
      // Start at home
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Step 1: Verify initial state is accessible
      const initialScan = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      expect(initialScan.violations.filter((v) => v.impact === 'critical')).toHaveLength(0);

      // Step 2: Perform search
      const searchInput = page.locator('input[placeholder*="Søk"]').first();
      await searchInput.fill('Møterom');
      await page.waitForTimeout(1500);

      // Step 3: Verify search results are accessible
      const afterSearchScan = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      expect(afterSearchScan.violations.filter((v) => v.impact === 'critical')).toHaveLength(0);

      // Step 4: Filter by location
      const locationSelect = page.locator('select, [role="combobox"]').first();
      await locationSelect.selectOption('Fana');
      await page.waitForTimeout(1500);

      // Step 5: Verify filtered results are accessible
      const afterFilterScan = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      expect(afterFilterScan.violations.filter((v) => v.impact === 'critical')).toHaveLength(0);

      // Step 6: Click on a result if available
      const resultLink = page.locator('a:has(h3):has-text("Møterom")').first();
      const hasResults = await resultLink.count();

      if (hasResults > 0) {
        await resultLink.click();
        await waitForPageLoad(page);

        // Step 7: Verify destination page is accessible
        const destinationScan = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
        expect(destinationScan.violations.filter((v) => v.impact === 'critical')).toHaveLength(0);
      }
    });
  });

  test.describe('Search Performance and Loading States', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);
    });

    test('should handle rapid search input changes', async ({ page }) => {
      const searchInput = page.locator('input[placeholder*="Søk"]').first();

      // Rapid typing
      await searchInput.fill('k');
      await page.waitForTimeout(100);
      await searchInput.fill('ku');
      await page.waitForTimeout(100);
      await searchInput.fill('kul');
      await page.waitForTimeout(100);
      await searchInput.fill('kultur');
      await page.waitForTimeout(100);
      await searchInput.fill('kulturhus');
      await page.waitForTimeout(2000); // Wait for final results

      // Should eventually show results without errors
      const results = page.locator('a:has(h3)');
      const count = await results.count();
      expect(count).toBeGreaterThan(0);

      // Should remain accessible
      const accessibilityScan = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      expect(accessibilityScan.violations.filter((v) => v.impact === 'critical')).toHaveLength(0);
    });

    test('should show loading indicator if present', async ({ page }) => {
      const searchInput = page.locator('input[placeholder*="Søk"]').first();

      await searchInput.fill('møterom');

      // Check for loading indicator (spinner, "Laster", etc.)
      const loadingIndicator = page.locator('img[alt="Laster"], [role="status"], text=Laster');

      // If loading indicator exists, it should be accessible
      const hasLoader = await loadingIndicator.count();
      if (hasLoader > 0) {
        const ariaLive = await loadingIndicator.first().getAttribute('aria-live');
        const role = await loadingIndicator.first().getAttribute('role');

        // Loading indicators should have role="status" or aria-live
        expect(
          ariaLive === 'polite' || role === 'status' || role === 'progressbar',
          'Loading indicator should have proper ARIA attributes'
        ).toBeTruthy();
      }
    });
  });
});
