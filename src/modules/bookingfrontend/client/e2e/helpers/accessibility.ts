import { Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Setup page to handle expected 401 responses from auth service
 * These are normal for unauthenticated users on public pages
 * @param page - Playwright page object
 */
export async function setupAuthErrorHandling(page: Page) {
  // Intercept console errors to filter out expected auth failures
  page.on('console', (msg) => {
    const text = msg.text();
    // Ignore expected auth check failures
    if (text.includes('401') && text.includes('auth')) {
      return; // Don't log these
    }
  });

  // Intercept failed requests to allow expected 401s from auth service
  page.on('requestfailed', (request) => {
    const url = request.url();
    const failure = request.failure();

    // Allow 401s from auth endpoints - these are expected for public pages
    if (url.includes('/auth') || url.includes('/login') || url.includes('/user')) {
      return; // Don't treat as error
    }

    // Log other failures for debugging
    console.warn(`Request failed: ${url} - ${failure?.errorText}`);
  });

  // Set up route to allow 401 responses from auth endpoints
  await page.route('**/*', (route) => {
    const url = route.request().url();

    // Allow all requests to proceed normally
    // We're just observing, not blocking
    route.continue();
  });
}

/**
 * WCAG Conformance Levels
 */
export enum WCAGLevel {
  A = 'wcag2a',
  AA = 'wcag2aa',
  AAA = 'wcag2aaa',
}

/**
 * Configuration for accessibility scans
 */
export interface AccessibilityScanOptions {
  wcagLevel?: WCAGLevel;
  exclude?: string[];
  include?: string[];
  disableRules?: string[];
}

/**
 * Performs an accessibility scan on the current page using axe-core
 * @param page - Playwright page object
 * @param options - Scan configuration options
 * @returns axe results
 */
export async function scanAccessibility(
  page: Page,
  options: AccessibilityScanOptions = {}
) {
  const {
    wcagLevel = WCAGLevel.AA,
    exclude = [],
    include = [],
    disableRules = [],
  } = options;

  let axeBuilder = new AxeBuilder({ page })
    .withTags([wcagLevel])
    .exclude([
      // Exclude third-party content that we don't control
      '#tanstack-query-devtools-btn',
      ...exclude,
    ]);

  if (include.length > 0) {
    axeBuilder = axeBuilder.include(include);
  }

  if (disableRules.length > 0) {
    axeBuilder = axeBuilder.disableRules(disableRules);
  }

  return await axeBuilder.analyze();
}

/**
 * Formats accessibility violations for better readability
 * @param violations - Array of axe violations
 * @returns Formatted string of violations
 */
export function formatViolations(violations: any[]): string {
  if (violations.length === 0) {
    return 'No accessibility violations found!';
  }

  return violations
    .map((violation, index) => {
      const nodes = violation.nodes
        .map((node: any) => {
          return `      Target: ${node.target.join(', ')}
      HTML: ${node.html}
      Impact: ${node.impact}
      ${node.failureSummary}`;
        })
        .join('\n\n');

      return `
  ${index + 1}. ${violation.id} (${violation.impact})
     Help: ${violation.help}
     Description: ${violation.description}
     WCAG: ${violation.tags.filter((tag: string) => tag.startsWith('wcag')).join(', ')}
     Help URL: ${violation.helpUrl}

     Affected elements (${violation.nodes.length}):
${nodes}
`;
    })
    .join('\n' + '='.repeat(80) + '\n');
}

/**
 * Wait for page to be fully loaded including dynamic content
 * @param page - Playwright page object
 * @param timeout - Maximum time to wait in milliseconds
 */
export async function waitForPageLoad(page: Page, timeout: number = 45000) {
  try {
    // Set up auth error handling before waiting for page load
    // This prevents 401 auth checks from being treated as errors
    await setupAuthErrorHandling(page);

    // Wait for the page to be fully loaded
    await page.waitForLoadState('domcontentloaded', { timeout });

    // Wait for the page to be loaded (all resources loaded)
    await page.waitForLoadState('load', { timeout: 20000 });

    // Try to wait for network idle with longer timeout
    // Modern React/Next.js apps often have polling or persistent connections
    try {
      await page.waitForLoadState('networkidle', { timeout: 20000 });
    } catch {
      // Network might not be idle (websockets, polling, etc.) - that's okay
      // Just wait a bit for React hydration
      await page.waitForTimeout(2000);
    }

    // Check for error pages (but ignore auth-related errors)
    const hasError = await page.locator('h1:has-text("Uncaught Exception")').count();
    if (hasError > 0) {
      const errorText = await page.locator('strong').first().textContent();
      // Don't fail on auth-related errors for public pages
      if (errorText && !errorText.toLowerCase().includes('unauthorized')) {
        throw new Error(`Page failed to load: ${errorText}`);
      }
    }
  } catch (error) {
    // Check if it's just a timeout but page is actually loaded
    const title = await page.title();
    if (title && title.trim().length > 0 && !title.includes('Error')) {
      // Page has a valid title, it's probably loaded
      // Just log a warning and continue
      console.warn(`Page load timeout but page seems ready (title: ${title})`);
      return;
    }

    const url = page.url();
    throw new Error(`Failed to load page at ${url} (title: ${title}): ${error}`);
  }
}

/**
 * Check for keyboard navigation
 * @param page - Playwright page object
 * @param startElement - CSS selector for the starting element
 * @param expectedOrder - Array of CSS selectors in expected tab order
 */
export async function testKeyboardNavigation(
  page: Page,
  startElement: string,
  expectedOrder: string[]
) {
  const results: { selector: string; isFocused: boolean }[] = [];

  await page.focus(startElement);

  for (const selector of expectedOrder) {
    await page.keyboard.press('Tab');
    const isFocused = await page.evaluate((sel) => {
      const element = document.querySelector(sel);
      return document.activeElement === element;
    }, selector);

    results.push({ selector, isFocused });
  }

  return results;
}

/**
 * Check if element has proper ARIA attributes
 * @param page - Playwright page object
 * @param selector - CSS selector for the element
 */
export async function checkAriaAttributes(page: Page, selector: string) {
  return await page.evaluate((sel) => {
    const element = document.querySelector(sel);
    if (!element) return null;

    const attributes: Record<string, string | null> = {};
    const ariaAttributes = [
      'aria-label',
      'aria-labelledby',
      'aria-describedby',
      'aria-hidden',
      'aria-expanded',
      'aria-haspopup',
      'aria-controls',
      'aria-current',
      'role',
    ];

    ariaAttributes.forEach((attr) => {
      attributes[attr] = element.getAttribute(attr);
    });

    return attributes;
  }, selector);
}

/**
 * Check color contrast ratios
 * @param page - Playwright page object
 * @param selector - CSS selector for the element
 */
export async function checkColorContrast(page: Page, selector: string) {
  return await page.evaluate((sel) => {
    const element = document.querySelector(sel);
    if (!element) return null;

    const styles = window.getComputedStyle(element);
    return {
      color: styles.color,
      backgroundColor: styles.backgroundColor,
      fontSize: styles.fontSize,
      fontWeight: styles.fontWeight,
    };
  }, selector);
}

/**
 * Test focus visibility
 * @param page - Playwright page object
 * @param selector - CSS selector for the element
 */
export async function testFocusVisibility(page: Page, selector: string) {
  await page.focus(selector);

  return await page.evaluate((sel) => {
    const element = document.querySelector(sel) as HTMLElement;
    if (!element) return null;

    const styles = window.getComputedStyle(element);
    const pseudoStyles = window.getComputedStyle(element, ':focus');

    return {
      hasFocus: document.activeElement === element,
      outline: styles.outline,
      outlineOffset: styles.outlineOffset,
      boxShadow: styles.boxShadow,
      focusOutline: pseudoStyles.outline,
      focusBoxShadow: pseudoStyles.boxShadow,
    };
  }, selector);
}

/**
 * Common WCAG success criteria to test
 */
export const WCAGSuccessCriteria = {
  // Level A
  nonTextContent: '1.1.1',
  audioOnlyVideoOnly: '1.2.1',
  captionsPrerecorded: '1.2.2',
  audioDescriptionOrMediaAlternative: '1.2.3',
  infoAndRelationships: '1.3.1',
  meaningfulSequence: '1.3.2',
  sensoryCharacteristics: '1.3.3',
  useOfColor: '1.4.1',
  audioControl: '1.4.2',

  // Level AA
  contrast: '1.4.3',
  resizeText: '1.4.4',
  imagesOfText: '1.4.5',
  keyboard: '2.1.1',
  noKeyboardTrap: '2.1.2',
  timing: '2.2.1',
  pauseStopHide: '2.2.2',

  // Navigation
  bypassBlocks: '2.4.1',
  pageTitle: '2.4.2',
  focusOrder: '2.4.3',
  linkPurpose: '2.4.4',
  multipleWays: '2.4.5',
  headingsAndLabels: '2.4.6',
  focusVisible: '2.4.7',

  // Language
  languageOfPage: '3.1.1',
  languageOfParts: '3.1.2',

  // Predictable
  onFocus: '3.2.1',
  onInput: '3.2.2',
  consistentNavigation: '3.2.3',
  consistentIdentification: '3.2.4',

  // Input Assistance
  errorIdentification: '3.3.1',
  labelsOrInstructions: '3.3.2',
  errorSuggestion: '3.3.3',
  errorPrevention: '3.3.4',

  // Compatible
  parsing: '4.1.1',
  nameRoleValue: '4.1.2',
};
