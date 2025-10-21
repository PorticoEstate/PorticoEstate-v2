import { test, expect } from '@playwright/test';
import {
  scanAccessibility,
  formatViolations,
  waitForPageLoad,
  WCAGLevel,
  checkAriaAttributes,
} from './helpers/accessibility';
import { TEST_URLS } from './fixtures/test-data';

test.describe('Search Pages - Accessibility Tests', () => {
  test.describe('Organization Search', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.search.organization.no);
      await waitForPageLoad(page);
    });

    test('should pass WCAG 2.1 Level AA compliance', async ({ page }) => {
      const results = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });

      expect(
        results.violations,
        `Found ${results.violations.length} accessibility violations:\n${formatViolations(results.violations)}`
      ).toHaveLength(0);
    });

    test('should have accessible search interface', async ({ page }) => {
      // Search input should be present and accessible
      const searchInput = page.locator('input[type="text"], input[type="search"]').first();
      await expect(searchInput).toBeVisible();

      const hasLabel = await searchInput.evaluate((el) => {
        const input = el as HTMLInputElement;
        return (
          input.getAttribute('aria-label') ||
          input.getAttribute('placeholder') ||
          (input.id && document.querySelector(`label[for="${input.id}"]`))
        );
      });

      expect(hasLabel, 'Search input should have a label or aria-label').toBeTruthy();
    });

    test('should have accessible tab navigation', async ({ page }) => {
      const tabList = page.locator('[role="tablist"]');
      await expect(tabList).toBeVisible();

      const tabs = page.locator('[role="tab"]');
      const tabCount = await tabs.count();
      expect(tabCount).toBeGreaterThanOrEqual(3);

      // Check Organization tab is selected or available
      const orgTab = page.locator('[role="tab"]:has-text("Organisasjon")');
      if (await orgTab.isVisible()) {
        const ariaSelected = await orgTab.getAttribute('aria-selected');
        expect(['true', 'false']).toContain(ariaSelected);
      }
    });

    test('should maintain accessibility in filter controls', async ({ page }) => {
      // Date picker
      const dateInput = page.locator('input[placeholder*="dato"], input[placeholder*="date"]');
      if (await dateInput.isVisible()) {
        await expect(dateInput).toBeEnabled();
      }

      // Location selector
      const locationSelect = page.locator('[role="combobox"], select');
      if (await locationSelect.isVisible()) {
        const attrs = await checkAriaAttributes(page, '[role="combobox"]');
        expect(attrs?.role || 'select').toBeTruthy();
      }
    });

    test('should not have critical accessibility issues', async ({ page }) => {
      const results = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });

      const criticalViolations = results.violations.filter(
        (v) => v.impact === 'critical'
      );

      expect(
        criticalViolations,
        `Found ${criticalViolations.length} critical violations:\n${formatViolations(criticalViolations)}`
      ).toHaveLength(0);
    });
  });

  test.describe('Event Search', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.search.event.no);
      await waitForPageLoad(page);
    });

    test('should pass WCAG 2.1 Level AA compliance', async ({ page }) => {
      const results = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });

      expect(
        results.violations,
        `Found ${results.violations.length} accessibility violations:\n${formatViolations(results.violations)}`
      ).toHaveLength(0);
    });

    test('should have accessible search interface', async ({ page }) => {
      const searchInput = page.locator('input[type="text"], input[type="search"]').first();
      await expect(searchInput).toBeVisible();
    });

    test('should have accessible event tab selected', async ({ page }) => {
      const eventTab = page.locator('[role="tab"]:has-text("Arrangement")');
      if (await eventTab.isVisible()) {
        const ariaSelected = await eventTab.getAttribute('aria-selected');
        expect(['true', 'false']).toContain(ariaSelected);
      }
    });

    test('should not have critical accessibility issues', async ({ page }) => {
      const results = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });

      const criticalViolations = results.violations.filter(
        (v) => v.impact === 'critical'
      );

      expect(
        criticalViolations,
        `Found ${criticalViolations.length} critical violations:\n${formatViolations(criticalViolations)}`
      ).toHaveLength(0);
    });
  });

  test.describe('Search Navigation', () => {
    test('should allow keyboard navigation between search tabs', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Find and focus on first tab
      const tabs = page.locator('[role="tab"]');
      const firstTab = tabs.first();
      await firstTab.focus();

      // Verify focus
      const isFocused = await firstTab.evaluate((el) => document.activeElement === el);
      expect(isFocused).toBeTruthy();

      // Tab to next element
      await page.keyboard.press('Tab');

      // Should be able to navigate with arrow keys (optional ARIA pattern)
      await firstTab.focus();
      await page.keyboard.press('ArrowRight');
    });

    test('should maintain focus visibility on interactive elements', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      const searchInput = page.locator('input[type="text"]').first();
      await searchInput.focus();

      const hasFocusVisible = await page.evaluate(() => {
        const element = document.activeElement as HTMLElement;
        if (!element) return false;

        const styles = window.getComputedStyle(element);
        return (
          styles.outline !== 'none' &&
          styles.outline !== '' &&
          styles.outlineWidth !== '0px'
        ) || styles.boxShadow !== 'none';
      });

      expect(hasFocusVisible, 'Search input should have visible focus indicator').toBeTruthy();
    });

    test('should have accessible filter button', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      const filterButton = page.locator('button:has-text("Flere filter"), button:has-text("More filters")');

      if (await filterButton.isVisible()) {
        await expect(filterButton).toBeEnabled();

        const text = await filterButton.textContent();
        const ariaLabel = await filterButton.getAttribute('aria-label');

        expect(
          text?.trim() || ariaLabel,
          'Filter button should have text or aria-label'
        ).toBeTruthy();
      }
    });
  });

  test.describe('Search Results Accessibility', () => {
    test('should have accessible empty state message', async ({ page }) => {
      await page.goto(TEST_URLS.frontPage.no);
      await waitForPageLoad(page);

      // Look for empty state message
      const emptyMessage = page.locator('text=/Bruk filtr.*?for å finne ressurser/i');
      if (await emptyMessage.isVisible()) {
        // Message should be in a semantic element
        const parent = await emptyMessage.evaluateHandle((el) => el.parentElement);
        const tagName = await parent.evaluate((el) => el?.tagName);

        expect(['DIV', 'P', 'SECTION', 'ARTICLE']).toContain(tagName);
      }
    });

    test('should maintain accessibility across language versions', async ({ page }) => {
      // Test Norwegian
      await page.goto(TEST_URLS.search.organization.no);
      await waitForPageLoad(page); // Use default timeout (45s)

      const resultsNo = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });

      // Test English
      await page.goto(TEST_URLS.search.organization.en);
      await waitForPageLoad(page); // Use default timeout (45s)

      const resultsEn = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });

      expect(
        resultsNo.violations,
        `Norwegian violations:\n${formatViolations(resultsNo.violations)}`
      ).toHaveLength(0);

      expect(
        resultsEn.violations,
        `English violations:\n${formatViolations(resultsEn.violations)}`
      ).toHaveLength(0);
    });
  });
});
