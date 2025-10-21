import { test, expect } from '@playwright/test';
import {
  scanAccessibility,
  formatViolations,
  waitForPageLoad,
  WCAGLevel,
  checkAriaAttributes,
} from './helpers/accessibility';
import { TEST_URLS, PAGE_TITLES } from './fixtures/test-data';

test.describe('Building Pages - Accessibility Tests', () => {
  test.describe('Building 10 - Fana kulturhus (with timeslots)', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page); // Use default timeout (45s)
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

    test('should have accessible building header', async ({ page }) => {
      // Building name should be in h2
      const buildingName = page.locator('h2:has-text("Fana kulturhus")');
      await expect(buildingName).toBeVisible();

      // Address button should be accessible
      const addressButton = page.locator('button:has-text("ØSTRE NESTTUNVEIEN")');
      await expect(addressButton).toBeVisible();
      await expect(addressButton).toBeEnabled();
    });

    test('should have accessible image gallery', async ({ page }) => {
      // Images should have alt text
      const images = page.locator('img[alt]').filter({ hasNotText: 'Logo' });
      const imageCount = await images.count();
      expect(imageCount).toBeGreaterThan(0);

      for (let i = 0; i < imageCount; i++) {
        const img = images.nth(i);
        const alt = await img.getAttribute('alt');
        expect(alt, `Image at index ${i} should have alt text`).toBeTruthy();
        expect(alt?.trim(), `Image alt text should not be empty`).not.toBe('');
      }
    });

    test('should have accessible accordion components', async ({ page }) => {
      // Find accordion buttons
      const accordionButtons = page.locator('button[aria-expanded]');
      const count = await accordionButtons.count();
      expect(count).toBeGreaterThan(0);

      // Test first accordion
      const firstAccordion = accordionButtons.first();
      const ariaExpanded = await firstAccordion.getAttribute('aria-expanded');
      expect(['true', 'false']).toContain(ariaExpanded);

      // Click and verify it toggles
      await firstAccordion.click();
      await page.waitForTimeout(300);

      const newAriaExpanded = await firstAccordion.getAttribute('aria-expanded');
      expect(newAriaExpanded).not.toBe(ariaExpanded);
    });

    test('should have accessible timeslot resource selection', async ({ page }) => {
      // Timeslot resources section should be present
      const timeslotSection = page.locator('text=Tidsslot ressurser');
      await expect(timeslotSection).toBeVisible();

      // Radio button for timeslot resource should be accessible
      const radioButton = page.locator('input[type="radio"]').first();
      const radioLabel = await radioButton.evaluate((el) => {
        const radio = el as HTMLInputElement;
        const label = document.querySelector(`label[for="${radio.id}"]`);
        return label?.textContent || radio.getAttribute('aria-label');
      });

      expect(radioLabel, 'Radio button should have a label').toBeTruthy();
    });

    test('should have accessible calendar resource checkboxes', async ({ page }) => {
      // Calendar resources section
      const calendarSection = page.locator('text=Kalender ressurser');
      await expect(calendarSection).toBeVisible();

      // Checkboxes should have proper labels
      const checkboxes = page.locator('input[type="checkbox"]');
      const checkboxCount = await checkboxes.count();
      expect(checkboxCount).toBeGreaterThan(0);

      // Test "Velg alle" (Select all) checkbox
      const selectAllCheckbox = page.locator('input[type="checkbox"]:near(:text("Velg alle"))').first();
      if (await selectAllCheckbox.isVisible()) {
        await expect(selectAllCheckbox).toBeEnabled();
      }
    });

    test('should have accessible calendar navigation', async ({ page }) => {
      // Calendar view buttons
      const dagButton = page.locator('button:has-text("Dag")');
      const ukeButton = page.locator('button:has-text("Uke")');
      const månedButton = page.locator('button:has-text("Måned")');

      await expect(dagButton).toBeVisible();
      await expect(ukeButton).toBeVisible();
      await expect(månedButton).toBeVisible();

      // Date navigation
      const dateInput = page.locator('input[value*="oktober"]').first();
      await expect(dateInput).toBeVisible();

      // Previous/Next buttons should be accessible
      const navButtons = page.locator('button[aria-label*="Previous"], button[aria-label*="Next"], button:has-text(""), button:has-text("")');
      const navCount = await navButtons.count();
      expect(navCount).toBeGreaterThan(0);
    });

    test('should have accessible "Ny søknad" button', async ({ page }) => {
      const newApplicationButton = page.locator('button:has-text("Ny søknad")');
      await expect(newApplicationButton).toBeVisible();
      await expect(newApplicationButton).toBeEnabled();

      // Should have proper attributes
      const attrs = await checkAriaAttributes(
        page,
        'button:has-text("Ny søknad")'
      );
      expect(attrs).toBeTruthy();
    });

    test('should have accessible contact information', async ({ page }) => {
      // Contact heading
      const contactHeading = page.locator('h3:has-text("Kontakt")').first();
      await expect(contactHeading).toBeVisible();

      // Phone and email should be present
      const phoneText = page.locator('text=/\\d{8}/').first(); // 8-digit phone number
      await expect(phoneText).toBeVisible();

      const emailText = page.locator('text=@bergen.kommune.no').first();
      await expect(emailText).toBeVisible();
    });

    test('should support keyboard navigation through calendar controls', async ({ page }) => {
      // Focus on first calendar control
      const dagButton = page.locator('button:has-text("Dag")');
      await dagButton.focus();

      // Tab through view buttons
      await page.keyboard.press('Tab');
      let focused = await page.evaluate(() => document.activeElement?.textContent);
      expect(['Uke', 'Måned', 'Kalender', 'Liste']).toContain(focused?.trim() || '');
    });
  });

  test.describe('Building 46 - Fløyen friluftsområde', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.buildings.building46.no);
      await waitForPageLoad(page); // Use default timeout (45s)
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

    test('should have accessible building information', async ({ page }) => {
      // Building name
      const buildingName = page.locator('h2:has-text("Fløyen friluftsområde")');
      await expect(buildingName).toBeVisible();

      // Location information
      const location = page.locator('text=BERGEN');
      await expect(location).toBeVisible();
    });

    test('should have accessible accordion sections', async ({ page }) => {
      // Utleieressurser accordion
      const utleieressurser = page.locator('button:has-text("Utleieressurser")');
      await expect(utleieressurser).toBeVisible();

      const ariaExpanded = await utleieressurser.getAttribute('aria-expanded');
      expect(['true', 'false']).toContain(ariaExpanded);

      // Beskrivelse accordion
      const beskrivelse = page.locator('button:has-text("Beskrivelse")');
      await expect(beskrivelse).toBeVisible();

      // Dokumenter accordion
      const dokumenter = page.locator('button:has-text("Dokumenter")');
      await expect(dokumenter).toBeVisible();
    });

    test('should have accessible calendar interface', async ({ page }) => {
      // Building selector button
      const buildingButton = page.locator('button:has-text("Fløyen friluftsområde")');
      await expect(buildingButton).toBeVisible();

      // Calendar view buttons
      const viewButtons = page.locator('button:has-text("Dag"), button:has-text("Uke"), button:has-text("Måned")');
      const count = await viewButtons.count();
      expect(count).toBeGreaterThanOrEqual(3);
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

  test.describe('Cross-building tests', () => {
    test('should maintain accessibility across different buildings', async ({ page }) => {
      // Test building 10
      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page); // Use default timeout (45s)

      const results1 = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      const violations1 = results1.violations.length;

      // Test building 46
      await page.goto(TEST_URLS.buildings.building46.no);
      await waitForPageLoad(page); // Use default timeout (45s)

      const results2 = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      const violations2 = results2.violations.length;

      // Both should have no violations
      expect(violations1, `Building 10 violations:\n${formatViolations(results1.violations)}`).toBe(0);
      expect(violations2, `Building 46 violations:\n${formatViolations(results2.violations)}`).toBe(0);
    });
  });
});
