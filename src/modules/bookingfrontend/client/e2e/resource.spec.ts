import { test, expect } from '@playwright/test';
import {
  scanAccessibility,
  formatViolations,
  waitForPageLoad,
  WCAGLevel,
  checkAriaAttributes,
} from './helpers/accessibility';
import { TEST_URLS } from './fixtures/test-data';

test.describe('Resource Page - Accessibility Tests', () => {
  test.describe('Resource 452 - TUBAKUBA (gropsal)', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.resources.resource452.no);
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

    test('should have accessible resource header', async ({ page }) => {
      // Resource name should be in h2
      const resourceName = page.locator('h2:has-text("TUBAKUBA")');
      await expect(resourceName).toBeVisible();

      // Location information should be present
      const location = page.locator('text=BERGEN');
      await expect(location).toBeVisible();

      // Address button should be accessible
      const addressButton = page.locator('button:has-text("JOHANNES BRUNS GATE")');
      await expect(addressButton).toBeVisible();
      await expect(addressButton).toBeEnabled();
    });

    test('should have accessible building link', async ({ page }) => {
      // Link back to building should be accessible
      const buildingLink = page.locator('a:has-text("Fløyen friluftsområde")');
      await expect(buildingLink).toBeVisible();
      await expect(buildingLink).toHaveAttribute('href');

      const href = await buildingLink.getAttribute('href');
      expect(href).toContain('/building/');
    });

    test('should have accessible image gallery', async ({ page }) => {
      // Images should have alt text
      const images = page.locator('img[alt]').filter({ hasNotText: 'Logo' }).filter({ hasNotText: 'Laster' });
      const imageCount = await images.count();

      if (imageCount > 0) {
        for (let i = 0; i < Math.min(imageCount, 5); i++) {
          const img = images.nth(i);
          const isVisible = await img.isVisible();

          if (isVisible) {
            const alt = await img.getAttribute('alt');
            expect(alt, `Visible image at index ${i} should have alt text`).toBeTruthy();
          }
        }
      }

      // "Vis alle bilder" button should be accessible if present
      const showAllButton = page.locator('button:has-text("Vis alle bilder"), text=Vis alle bilder');
      if (await showAllButton.isVisible()) {
        await expect(showAllButton).toBeEnabled();
      }
    });

    test('should have accessible accordion components', async ({ page }) => {
      // Find accordion buttons
      const accordionButtons = page.locator('button[aria-expanded]');
      const count = await accordionButtons.count();
      expect(count).toBeGreaterThan(0);

      // Check each accordion for proper ARIA attributes
      for (let i = 0; i < count; i++) {
        const accordion = accordionButtons.nth(i);
        const ariaExpanded = await accordion.getAttribute('aria-expanded');
        expect(['true', 'false'], `Accordion ${i} should have valid aria-expanded`).toContain(ariaExpanded);

        const text = await accordion.textContent();
        expect(text?.trim(), `Accordion ${i} should have text`).toBeTruthy();
      }
    });

    test('should have accessible description accordion', async ({ page }) => {
      const beskrivelseButton = page.locator('button:has-text("Beskrivelse")');

      if (await beskrivelseButton.isVisible()) {
        await expect(beskrivelseButton).toBeEnabled();

        // Click to expand
        const initialExpanded = await beskrivelseButton.getAttribute('aria-expanded');
        await beskrivelseButton.click();
        await page.waitForTimeout(300);

        const newExpanded = await beskrivelseButton.getAttribute('aria-expanded');
        expect(newExpanded).not.toBe(initialExpanded);
      }
    });

    test('should have accessible opening hours section', async ({ page }) => {
      const openingHoursButton = page.locator('button:has-text("Åpningstider")');

      if (await openingHoursButton.isVisible()) {
        await expect(openingHoursButton).toBeEnabled();

        const ariaExpanded = await openingHoursButton.getAttribute('aria-expanded');
        expect(['true', 'false']).toContain(ariaExpanded);
      }
    });

    test('should have accessible contact information section', async ({ page }) => {
      const contactButton = page.locator('button:has-text("Kontaktinformasjon")');

      if (await contactButton.isVisible()) {
        await expect(contactButton).toBeEnabled();

        const ariaExpanded = await contactButton.getAttribute('aria-expanded');
        expect(['true', 'false']).toContain(ariaExpanded);
      }
    });

    test('should have accessible documents section', async ({ page }) => {
      const documentsButton = page.locator('button:has-text("Dokumenter")');

      if (await documentsButton.isVisible()) {
        await expect(documentsButton).toBeEnabled();

        const ariaExpanded = await documentsButton.getAttribute('aria-expanded');
        expect(['true', 'false']).toContain(ariaExpanded);
      }
    });

    test('should have accessible calendar interface', async ({ page }) => {
      // Building selector button (should show building name)
      const buildingButton = page.locator('button:has-text("Fløyen friluftsområde")');
      await expect(buildingButton).toBeVisible();

      // Date input
      const dateInput = page.locator('input[value*="oktober"]').first();
      if (await dateInput.isVisible()) {
        await expect(dateInput).toBeEnabled();
      }

      // Calendar view buttons
      const dagButton = page.locator('button:has-text("Dag")');
      const ukeButton = page.locator('button:has-text("Uke")');
      const månedButton = page.locator('button:has-text("Måned")');

      await expect(dagButton).toBeVisible();
      await expect(ukeButton).toBeVisible();
      await expect(månedButton).toBeVisible();

      // New application button
      const newApplicationButton = page.locator('button:has-text("Ny søknad")');
      await expect(newApplicationButton).toBeVisible();
      await expect(newApplicationButton).toBeEnabled();
    });

    test('should support keyboard navigation through accordions', async ({ page }) => {
      const accordionButtons = page.locator('button[aria-expanded]');
      const firstAccordion = accordionButtons.first();

      await firstAccordion.focus();
      const isFocused = await firstAccordion.evaluate((el) => document.activeElement === el);
      expect(isFocused, 'First accordion should be focusable').toBeTruthy();

      // Should be able to activate with keyboard
      await page.keyboard.press('Enter');
      await page.waitForTimeout(300);

      const ariaExpanded = await firstAccordion.getAttribute('aria-expanded');
      expect(ariaExpanded).toBeTruthy();
    });

    test('should have proper heading hierarchy', async ({ page }) => {
      // h2 should be the resource name
      const h2 = page.locator('h2');
      await expect(h2.first()).toBeVisible();

      // h3 should be for sections like "Kontakt"
      const h3 = page.locator('h3');
      const h3Count = await h3.count();
      expect(h3Count).toBeGreaterThan(0);

      // Should not skip heading levels
      const headings = await page.evaluate(() => {
        const allHeadings = Array.from(document.querySelectorAll('h1, h2, h3, h4, h5, h6'));
        return allHeadings.map((h) => h.tagName);
      });

      // Check that we don't skip from h2 to h4, etc.
      for (let i = 0; i < headings.length - 1; i++) {
        const current = parseInt(headings[i].replace('H', ''));
        const next = parseInt(headings[i + 1].replace('H', ''));
        const diff = next - current;
        expect(diff, `Should not skip heading levels: ${headings[i]} -> ${headings[i + 1]}`).toBeLessThanOrEqual(1);
      }
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
        `Found ${criticalViolations.length} critical violations:\n${formatViolations(criticalViolations)}`
      ).toHaveLength(0);
    });

    test('should have proper color contrast throughout page', async ({ page }) => {
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

    test('should maintain accessibility in English version', async ({ page }) => {
      await page.goto(TEST_URLS.resources.resource452.en);
      await waitForPageLoad(page); // Use default timeout (45s)

      const results = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });

      expect(
        results.violations,
        `English version violations:\n${formatViolations(results.violations)}`
      ).toHaveLength(0);
    });
  });
});
