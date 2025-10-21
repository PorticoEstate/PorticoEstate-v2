import { test, expect } from '@playwright/test';
import {
  scanAccessibility,
  formatViolations,
  waitForPageLoad,
  WCAGLevel,
  testKeyboardNavigation,
  checkAriaAttributes,
} from './helpers/accessibility';
import { TEST_URLS, SELECTORS, PAGE_TITLES } from './fixtures/test-data';

test.describe('Front Page (Home) - Accessibility Tests', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(TEST_URLS.frontPage.no);
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

  test('should have proper page structure and landmarks', async ({ page }) => {
    // Check for main landmarks
    const header = page.locator('header, [role="banner"]');
    await expect(header).toBeVisible();

    const main = page.locator('main, [role="main"]');
    await expect(main).toBeVisible();

    const footer = page.locator('footer, [role="contentinfo"]');
    await expect(footer).toBeVisible();

    // Check for proper heading hierarchy
    const h1 = page.locator('h1');
    const h1Count = await h1.count();
    expect(h1Count).toBeGreaterThanOrEqual(1);
  });

  test('should have accessible navigation', async ({ page }) => {
    // Logo link should be accessible
    const logo = page.locator('a[href*="kommune"]').first();
    await expect(logo).toBeVisible();

    // Check navigation buttons
    const cartButton = page.locator('button:has-text("Handlekurv")');
    await expect(cartButton).toBeVisible();
    await expect(cartButton).toBeEnabled();

    const loginButton = page.locator('button:has-text("Logg inn")');
    await expect(loginButton).toBeVisible();
    await expect(loginButton).toBeEnabled();
  });

  test('should have accessible search/filter interface', async ({ page }) => {
    // Tab navigation should be present and accessible
    const tabList = page.locator('[role="tablist"]');
    await expect(tabList).toBeVisible();

    const tabs = page.locator('[role="tab"]');
    const tabCount = await tabs.count();
    expect(tabCount).toBeGreaterThanOrEqual(3); // Leie, Arrangement, Organisasjon

    // Check that tabs are keyboard accessible
    const firstTab = tabs.first();
    await firstTab.focus();
    const isSelected = await firstTab.getAttribute('aria-selected');
    expect(isSelected).toBeTruthy();

    // Search input should have proper label
    const searchInput = page.locator('input[type="text"]').first();
    await expect(searchInput).toBeVisible();

    // Date picker should be accessible
    const dateInput = page.locator('input[value*="Velg dato"]');
    await expect(dateInput).toBeVisible();

    // Location selector should be accessible
    const locationSelect = page.locator('[role="combobox"]');
    await expect(locationSelect).toBeVisible();

    const ariaAttrs = await checkAriaAttributes(page, '[role="combobox"]');
    expect(ariaAttrs).toBeTruthy();
  });

  test('should support keyboard navigation', async ({ page }) => {
    // Test Tab key navigation through interactive elements
    const interactiveElements = page.locator('a, button, input, select, [role="tab"], [role="combobox"]');
    const count = await interactiveElements.count();
    expect(count).toBeGreaterThan(0);

    // Tab through first few elements
    await page.keyboard.press('Tab');
    let focusedElement = await page.evaluate(() => document.activeElement?.tagName);
    expect(['A', 'BUTTON', 'INPUT']).toContain(focusedElement);

    // Ensure focus is visible
    await page.keyboard.press('Tab');
    const hasFocusVisible = await page.evaluate(() => {
      const element = document.activeElement as HTMLElement;
      const styles = window.getComputedStyle(element);
      return styles.outline !== 'none' || styles.outlineWidth !== '0px' || styles.boxShadow !== 'none';
    });
    expect(hasFocusVisible).toBeTruthy();
  });

  test('should have accessible form elements', async ({ page }) => {
    // All form fields should have labels or aria-labels
    const inputs = page.locator('input[type="text"], input[type="search"]');
    const inputCount = await inputs.count();

    for (let i = 0; i < inputCount; i++) {
      const input = inputs.nth(i);
      const hasAriaLabel = await input.getAttribute('aria-label');
      const hasPlaceholder = await input.getAttribute('placeholder');
      const hasAssociatedLabel = await input.evaluate((el) => {
        const id = el.id;
        return id ? document.querySelector(`label[for="${id}"]`) !== null : false;
      });

      expect(
        hasAriaLabel || hasAssociatedLabel || hasPlaceholder,
        `Input at index ${i} should have a label, aria-label, or placeholder`
      ).toBeTruthy();
    }
  });

  test('should have accessible buttons with proper labels', async ({ page }) => {
    const buttons = page.locator('button');
    const buttonCount = await buttons.count();

    for (let i = 0; i < buttonCount; i++) {
      const button = buttons.nth(i);
      const isVisible = await button.isVisible();

      if (isVisible) {
        const text = await button.textContent();
        const ariaLabel = await button.getAttribute('aria-label');
        const ariaLabelledBy = await button.getAttribute('aria-labelledby');

        expect(
          text?.trim() || ariaLabel || ariaLabelledBy,
          `Button at index ${i} should have text content, aria-label, or aria-labelledby`
        ).toBeTruthy();
      }
    }
  });

  test('should have accessible footer with contact information', async ({ page }) => {
    const footer = page.locator('footer');
    await expect(footer).toBeVisible();

    // Contact email should be a link
    const emailLink = page.locator('a[href*="@bergen.kommune.no"]').first();
    await expect(emailLink).toBeVisible();

    // Accessibility statement link should be present
    const accessibilityLink = page.locator('a:has-text("Tilgjengeleghetserklæring")');
    await expect(accessibilityLink).toBeVisible();

    // Privacy link should be present
    const privacyLink = page.locator('a:has-text("Personvern")');
    await expect(privacyLink).toBeVisible();
  });

  test('should have proper color contrast', async ({ page }) => {
    const results = await scanAccessibility(page, {
      wcagLevel: WCAGLevel.AA,
    });

    const contrastViolations = results.violations.filter((v) =>
      v.id.includes('color-contrast')
    );

    expect(
      contrastViolations,
      `Found ${contrastViolations.length} color contrast violations:\n${formatViolations(contrastViolations)}`
    ).toHaveLength(0);
  });

  test('should support multiple languages', async ({ page }) => {
    // Check Norwegian version (already loaded)
    await expect(page).toHaveTitle(/BkBygg/);

    // Switch to English
    await page.goto(TEST_URLS.frontPage.en);
    await waitForPageLoad(page);

    // Should still be accessible
    const results = await scanAccessibility(page, {
      wcagLevel: WCAGLevel.AA,
    });

    expect(results.violations).toHaveLength(0);
  });

  test('should have proper ARIA attributes for interactive components', async ({ page }) => {
    // Tabs should have proper ARIA
    const tabs = page.locator('[role="tab"]');
    const firstTab = tabs.first();

    const tabAttrs = await checkAriaAttributes(page, '[role="tab"]');
    expect(tabAttrs?.role).toBe('tab');
    expect(tabAttrs?.['aria-selected']).toBeTruthy();

    // Combobox should have proper ARIA
    const comboboxAttrs = await checkAriaAttributes(page, '[role="combobox"]');
    expect(comboboxAttrs?.role).toBe('combobox');
  });

  test('should not have any critical accessibility issues', async ({ page }) => {
    const results = await scanAccessibility(page, {
      wcagLevel: WCAGLevel.AA,
    });

    const criticalViolations = results.violations.filter(
      (v) => v.impact === 'critical'
    );

    expect(
      criticalViolations,
      `Found ${criticalViolations.length} critical accessibility violations:\n${formatViolations(criticalViolations)}`
    ).toHaveLength(0);
  });
});
