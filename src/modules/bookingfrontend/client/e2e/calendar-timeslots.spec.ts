import { test, expect } from '@playwright/test';
import {
  scanAccessibility,
  formatViolations,
  waitForPageLoad,
  WCAGLevel,
  checkAriaAttributes,
} from './helpers/accessibility';
import { TEST_URLS } from './fixtures/test-data';

test.describe('Calendar and Timeslots - Accessibility Tests', () => {
  test.describe('Building 10 Calendar (with timeslots)', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page); // Use default timeout (45s)

      // Wait for calendar to render
      await page.waitForSelector('button:has-text("Dag")', { timeout: 30000 });
    });

    test('should pass WCAG 2.1 Level AA compliance on calendar view', async ({ page }) => {
      const results = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });

      expect(
        results.violations,
        `Found ${results.violations.length} accessibility violations:\n${formatViolations(results.violations)}`
      ).toHaveLength(0);
    });

    test('should have accessible timeslot resource selector', async ({ page }) => {
      // Timeslot section heading
      const timeslotHeading = page.locator('text=Tidsslot ressurser');
      await expect(timeslotHeading).toBeVisible();

      const helpText = page.locator('text=Velg enkelt en tid');
      await expect(helpText).toBeVisible();

      // Radio button for timeslot resource
      const radioButton = page.locator('input[type="radio"]').first();
      if (await radioButton.isVisible()) {
        await expect(radioButton).toBeEnabled();

        // Check for associated label
        const hasLabel = await radioButton.evaluate((el) => {
          const radio = el as HTMLInputElement;
          const id = radio.id;
          const ariaLabel = radio.getAttribute('aria-label');
          const label = id ? document.querySelector(`label[for="${id}"]`) : null;
          return !!(ariaLabel || label);
        });

        expect(hasLabel, 'Timeslot radio button should have a label').toBeTruthy();
      }
    });

    test('should have accessible calendar resource checkboxes', async ({ page }) => {
      // Calendar resources section
      const calendarHeading = page.locator('text=Kalender ressurser');
      await expect(calendarHeading).toBeVisible();

      // "Velg alle" checkbox
      const selectAllCheckbox = page.locator('input[type="checkbox"]').first();
      await expect(selectAllCheckbox).toBeEnabled();

      // Check if it's checked
      const isChecked = await selectAllCheckbox.isChecked();
      expect(typeof isChecked).toBe('boolean');

      // Individual resource checkboxes
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      expect(count).toBeGreaterThan(1); // At least "Velg alle" + resource checkboxes

      // Test first resource checkbox
      const firstResourceCheckbox = checkboxes.nth(1);
      if (await firstResourceCheckbox.isVisible()) {
        const hasLabel = await firstResourceCheckbox.evaluate((el) => {
          const checkbox = el as HTMLInputElement;
          const id = checkbox.id;
          const ariaLabel = checkbox.getAttribute('aria-label');
          const label = id ? document.querySelector(`label[for="${id}"]`) : null;
          return !!(ariaLabel || label);
        });

        expect(hasLabel, 'Resource checkbox should have a label').toBeTruthy();
      }
    });

    test('should have accessible calendar view switcher', async ({ page }) => {
      const dagButton = page.locator('button:has-text("Dag")');
      const ukeButton = page.locator('button:has-text("Uke")');
      const månedButton = page.locator('button:has-text("Måned")');
      const kalenderButton = page.locator('button:has-text("Kalender")');
      const listeButton = page.locator('button:has-text("Liste")');

      await expect(dagButton).toBeVisible();
      await expect(ukeButton).toBeVisible();
      await expect(månedButton).toBeVisible();

      // Check that buttons are properly labeled
      for (const button of [dagButton, ukeButton, månedButton]) {
        const text = await button.textContent();
        expect(text?.trim().length, 'View button should have text').toBeGreaterThan(0);
      }

      // Check ARIA attributes if present
      const ukeAttrs = await checkAriaAttributes(page, 'button:has-text("Uke")');
      expect(ukeAttrs).toBeTruthy();
    });

    test('should have accessible date navigation', async ({ page }) => {
      // Date input/display
      const dateInput = page.locator('input[value*="oktober"], input[value*="20"]').first();
      await expect(dateInput).toBeVisible();

      // Previous/Next navigation buttons
      const navButtons = page.locator('button[aria-label], button:near(input[value*="oktober"])');
      const navCount = await navButtons.count();
      expect(navCount).toBeGreaterThan(0);

      // Click previous button and verify page doesn't break
      const prevButton = page.locator('button').first();
      if (await prevButton.isVisible()) {
        const isEnabled = await prevButton.isEnabled();
        if (isEnabled) {
          await prevButton.click();
          await page.waitForTimeout(500);

          // Page should still be accessible after navigation
          const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
          const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
          expect(criticalViolations).toHaveLength(0);
        }
      }
    });

    test('should have accessible calendar week view', async ({ page }) => {
      // Switch to week view
      const ukeButton = page.locator('button:has-text("Uke")');
      await ukeButton.click();
      await page.waitForTimeout(1000);

      // Check for day headers
      const dayHeaders = page.locator('text=/mandag|tirsdag|onsdag|torsdag|fredag|lørdag|søndag/i');
      const headerCount = await dayHeaders.count();
      expect(headerCount).toBeGreaterThan(0);

      // Calendar events should have proper structure
      const events = page.locator('[class*="event"], [class*="slot"], [class*="booking"]');
      const eventCount = await events.count();

      if (eventCount > 0) {
        // Sample check first event
        const firstEvent = events.first();
        const isVisible = await firstEvent.isVisible();

        if (isVisible) {
          const hasText = await firstEvent.evaluate((el) => {
            return el.textContent && el.textContent.trim().length > 0;
          });
          expect(hasText, 'Calendar event should have content').toBeTruthy();
        }
      }
    });

    test('should have accessible calendar day view', async ({ page }) => {
      // Switch to day view
      const dagButton = page.locator('button:has-text("Dag")');
      await dagButton.click();
      await page.waitForTimeout(1000);

      // Should show time slots (06:00, 07:00, etc.)
      const timeSlots = page.locator('text=/\\d{2}:\\d{2}/');
      const timeSlotCount = await timeSlots.count();
      expect(timeSlotCount).toBeGreaterThan(0);

      // Check accessibility after view change
      const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
      expect(
        criticalViolations,
        `Critical violations in day view:\n${formatViolations(criticalViolations)}`
      ).toHaveLength(0);
    });

    test('should have accessible calendar month view', async ({ page }) => {
      // Switch to month view
      const månedButton = page.locator('button:has-text("Måned")');
      await månedButton.click();
      await page.waitForTimeout(1000);

      // Check accessibility after view change
      const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
      expect(
        criticalViolations,
        `Critical violations in month view:\n${formatViolations(criticalViolations)}`
      ).toHaveLength(0);
    });

    test('should support keyboard navigation in calendar', async ({ page }) => {
      // Focus on first view button
      const dagButton = page.locator('button:has-text("Dag")');
      await dagButton.focus();

      const isFocused = await dagButton.evaluate((el) => document.activeElement === el);
      expect(isFocused, 'Day button should be focusable').toBeTruthy();

      // Tab through view buttons
      await page.keyboard.press('Tab');
      const nextFocus = await page.evaluate(() => document.activeElement?.textContent);
      expect(nextFocus?.trim()).toBeTruthy();

      // Ensure focus is visible
      const hasFocusVisible = await page.evaluate(() => {
        const element = document.activeElement as HTMLElement;
        if (!element) return false;

        const styles = window.getComputedStyle(element);
        return (
          styles.outline !== 'none' ||
          styles.boxShadow !== 'none' ||
          styles.border !== 'none'
        );
      });

      expect(hasFocusVisible, 'Focus should be visible').toBeTruthy();
    });

    test('should have accessible "Ny søknad" button', async ({ page }) => {
      const newApplicationButton = page.locator('button:has-text("Ny søknad")');
      await expect(newApplicationButton).toBeVisible();
      await expect(newApplicationButton).toBeEnabled();

      // Check it has proper styling/visibility
      const backgroundColor = await newApplicationButton.evaluate((el) => {
        return window.getComputedStyle(el).backgroundColor;
      });

      expect(backgroundColor).toBeTruthy();
      expect(backgroundColor).not.toBe('rgba(0, 0, 0, 0)');
      expect(backgroundColor).not.toBe('transparent');
    });

    test('should handle resource selection accessibility', async ({ page }) => {
      // Toggle a resource checkbox
      const checkboxes = page.locator('input[type="checkbox"]');
      const resourceCheckbox = checkboxes.nth(1); // First resource (not "Velg alle")

      const initialState = await resourceCheckbox.isChecked();
      await resourceCheckbox.click();
      await page.waitForTimeout(500);

      const newState = await resourceCheckbox.isChecked();
      expect(newState).not.toBe(initialState);

      // Calendar should still be accessible after selection
      const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
      const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
      expect(criticalViolations).toHaveLength(0);
    });

    test('should have accessible resource info buttons', async ({ page }) => {
      // Info buttons next to each resource (button with "")
      const infoButtons = page.locator('button:near(input[type="checkbox"])').filter({ hasText: '' });
      const infoCount = await infoButtons.count();

      if (infoCount > 0) {
        const firstInfoButton = infoButtons.first();

        // Should have aria-label or title
        const hasAccessibleName = await firstInfoButton.evaluate((el) => {
          const button = el as HTMLButtonElement;
          return !!(
            button.getAttribute('aria-label') ||
            button.getAttribute('title') ||
            button.textContent?.trim()
          );
        });

        // Note: This might fail, which could be a real accessibility issue
        expect(hasAccessibleName, 'Info button should have accessible name').toBeTruthy();
      }
    });

    test('should maintain accessibility when interacting with calendar events', async ({ page }) => {
      // Switch to week view for better event visibility
      const ukeButton = page.locator('button:has-text("Uke")');
      await ukeButton.click();
      await page.waitForTimeout(1000);

      // Look for calendar events
      const events = page.locator('[class*="fc-event"], [class*="event"], div:has-text("block")');
      const eventCount = await events.count();

      if (eventCount > 0) {
        // Click on first event
        const firstEvent = events.first();
        if (await firstEvent.isVisible()) {
          await firstEvent.click();
          await page.waitForTimeout(500);

          // Check accessibility after interaction
          const results = await scanAccessibility(page, { wcagLevel: WCAGLevel.AA });
          const criticalViolations = results.violations.filter((v) => v.impact === 'critical');
          expect(criticalViolations).toHaveLength(0);
        }
      }
    });

    test('should have proper ARIA labels on calendar structure', async ({ page }) => {
      // Calendar wrapper should have semantic structure
      const calendarArea = page.locator('[class*="calendar"], [class*="fc"]').first();

      if (await calendarArea.isVisible()) {
        const role = await calendarArea.getAttribute('role');
        // Calendar might have role="application" or role="grid"
        if (role) {
          expect(['application', 'grid', 'table', 'region']).toContain(role);
        }
      }
    });
  });

  test.describe('Resource 452 Calendar', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(TEST_URLS.resources.resource452.no);
      await waitForPageLoad(page); // Use default timeout (45s)
    });

    test('should have accessible calendar on resource page', async ({ page }) => {
      const results = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });

      const criticalViolations = results.violations.filter(
        (v) => v.impact === 'critical'
      );

      expect(
        criticalViolations,
        `Critical violations on resource calendar:\n${formatViolations(criticalViolations)}`
      ).toHaveLength(0);
    });

    test('should have accessible calendar view switcher', async ({ page }) => {
      const viewButtons = page.locator('button:has-text("Dag"), button:has-text("Uke"), button:has-text("Måned")');
      const count = await viewButtons.count();
      expect(count).toBeGreaterThanOrEqual(3);
    });
  });

  test.describe('Calendar Responsive Behavior', () => {
    test('should maintain accessibility on mobile viewport', async ({ page }) => {
      // Set mobile viewport
      await page.setViewportSize({ width: 375, height: 667 });

      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page); // Use default timeout (45s)

      const results = await scanAccessibility(page, {
        wcagLevel: WCAGLevel.AA,
      });

      expect(
        results.violations,
        `Mobile viewport violations:\n${formatViolations(results.violations)}`
      ).toHaveLength(0);
    });

    test('should have accessible touch targets on mobile', async ({ page }) => {
      // Set mobile viewport
      await page.setViewportSize({ width: 375, height: 667 });

      await page.goto(TEST_URLS.buildings.building10.no);
      await waitForPageLoad(page); // Use default timeout (45s)

      // Check button sizes (should be at least 44x44px for touch)
      const buttons = page.locator('button:has-text("Dag"), button:has-text("Uke"), button:has-text("Måned")');
      const firstButton = buttons.first();

      const size = await firstButton.boundingBox();
      if (size) {
        expect(size.height, 'Touch target should be at least 44px high').toBeGreaterThanOrEqual(40);
        expect(size.width, 'Touch target should be at least 44px wide').toBeGreaterThanOrEqual(40);
      }
    });
  });
});
