# E2E and Accessibility Testing Suite

Comprehensive WCAG 2.1 Level AA accessibility testing for the Bookingfrontend client application using Playwright and axe-core.

## Table of Contents

- [Overview](#overview)
- [Setup](#setup)
- [Running Tests](#running-tests)
- [Test Structure](#test-structure)
- [Coverage](#coverage)
- [Understanding Results](#understanding-results)
- [Best Practices](#best-practices)

## Overview

This test suite validates accessibility compliance across the entire booking frontend application, focusing on:

- **WCAG 2.1 Level AA compliance** (primary target)
- **Keyboard navigation** and focus management
- **Screen reader compatibility** (ARIA attributes)
- **Color contrast** ratios
- **Semantic HTML** structure
- **Responsive design** accessibility

### Pages Tested

1. **Home/Front Page** (`/no/`)
2. **Search Pages** (`/no/search/organization`, `/no/search/event`)
3. **Building Pages**
   - Building 10: Fana kulturhus (with timeslots)
   - Building 46: Fløyen friluftsområde
4. **Resource Page** (Resource 452: TUBAKUBA)
5. **Calendar and Timeslots** (interactive booking interface)

## Setup

### Prerequisites

- Node.js 20+
- Yarn 1.22.22+
- Running instance at `https://pe-api.test/bookingfrontend/client`

### Installation

Dependencies are already installed via package.json:

```json
{
  "@playwright/test": "^1.56.1",
  "@axe-core/playwright": "^4.10.2",
  "axe-core": "^4.11.0"
}
```

Install Playwright browsers:

```bash
npx playwright install
```

### Configuration

The test configuration is in `playwright.config.ts`:

- **Base URL**: `https://pe-api.test/bookingfrontend/client`
- **Timeout**: 60 seconds per test
- **Retries**: 2 (in CI), 0 (locally)
- **Browsers**: Chromium, Firefox, WebKit, Mobile Chrome, Mobile Safari
- **HTTPS Errors**: Ignored (for local development with self-signed certs)

## Running Tests

### Run All Tests

```bash
# Run all tests across all browsers
npx playwright test

# Run tests in a specific browser
npx playwright test --project=chromium

# Run tests in headed mode (visible browser)
npx playwright test --headed

# Run tests in UI mode (interactive)
npx playwright test --ui
```

### Run Specific Test Suites

```bash
# Home page tests only
npx playwright test e2e/home-page.spec.ts

# Building pages tests
npx playwright test e2e/building.spec.ts

# Calendar/timeslot tests
npx playwright test e2e/calendar-timeslots.spec.ts

# Search tests
npx playwright test e2e/search.spec.ts

# Resource page tests
npx playwright test e2e/resource.spec.ts
```

### Run Tests by Pattern

```bash
# Run tests matching a pattern
npx playwright test -g "WCAG 2.1"

# Run tests for specific building
npx playwright test -g "Building 10"

# Run tests for keyboard navigation
npx playwright test -g "keyboard"
```

### Debug Tests

```bash
# Run tests with debug mode
npx playwright test --debug

# Debug specific test
npx playwright test e2e/home-page.spec.ts --debug

# Step through tests with Playwright Inspector
PWDEBUG=1 npx playwright test
```

## Test Structure

### Directory Layout

```
e2e/
├── README.md                      # This file
├── home-page.spec.ts              # Front page tests
├── search.spec.ts                 # Search functionality tests
├── building.spec.ts               # Building pages tests
├── resource.spec.ts               # Resource page tests
├── calendar-timeslots.spec.ts     # Calendar/booking tests
├── helpers/
│   └── accessibility.ts           # Accessibility helper functions
└── fixtures/
    └── test-data.ts               # Test data and constants
```

### Helper Functions

Located in `helpers/accessibility.ts`:

- `scanAccessibility()` - Run axe-core scan with WCAG level config
- `formatViolations()` - Format violations for readable output
- `waitForPageLoad()` - Wait for page and dynamic content
- `testKeyboardNavigation()` - Test tab order
- `checkAriaAttributes()` - Validate ARIA attributes
- `testFocusVisibility()` - Verify focus indicators
- `checkColorContrast()` - Check color contrast ratios

### Test Data

Located in `fixtures/test-data.ts`:

- URL paths for all pages
- Test IDs (buildings, resources)
- Common selectors
- Expected page titles
- Accessibility configuration

## Coverage

### WCAG Success Criteria Tested

#### Level A (Critical)

- ✅ 1.1.1 Non-text Content
- ✅ 1.3.1 Info and Relationships
- ✅ 1.3.2 Meaningful Sequence
- ✅ 2.1.1 Keyboard
- ✅ 2.1.2 No Keyboard Trap
- ✅ 2.4.1 Bypass Blocks
- ✅ 2.4.2 Page Titled
- ✅ 2.4.3 Focus Order
- ✅ 3.1.1 Language of Page
- ✅ 4.1.1 Parsing
- ✅ 4.1.2 Name, Role, Value

#### Level AA (Required)

- ✅ 1.4.3 Contrast (Minimum)
- ✅ 1.4.4 Resize Text
- ✅ 1.4.5 Images of Text
- ✅ 2.4.5 Multiple Ways
- ✅ 2.4.6 Headings and Labels
- ✅ 2.4.7 Focus Visible
- ✅ 3.2.3 Consistent Navigation
- ✅ 3.3.1 Error Identification
- ✅ 3.3.2 Labels or Instructions

### Component Coverage

- ✅ Navigation (header, logo, buttons)
- ✅ Search/Filter interface (inputs, selectors, tabs)
- ✅ Form elements (inputs, checkboxes, radio buttons)
- ✅ Accordions (expandable sections)
- ✅ Calendar views (day, week, month)
- ✅ Timeslot selection
- ✅ Resource selection
- ✅ Image galleries
- ✅ Footer links
- ✅ Modal dialogs (if present)

## Understanding Results

### Test Reports

After running tests, view results:

```bash
# View HTML report
npx playwright show-report

# View JSON results
cat test-results/results.json
```

### Report Locations

- **HTML Report**: `playwright-report/index.html`
- **JSON Results**: `test-results/results.json`
- **Screenshots**: `test-results/` (on failure)
- **Videos**: `test-results/` (on failure)
- **Traces**: Available on first retry

### Accessibility Violation Format

When accessibility violations are found, they're formatted as:

```
1. color-contrast (serious)
   Help: Elements must have sufficient color contrast
   Description: Ensures background/foreground color contrast meets WCAG AA
   WCAG: wcag2aa, wcag143

   Affected elements (2):
      Target: button.primary
      HTML: <button class="primary">Submit</button>
      Impact: serious
      Fix: Increase color contrast ratio to at least 4.5:1
```

### Violation Severity Levels

- **Critical**: Must fix immediately
- **Serious**: Should fix (fails WCAG AA)
- **Moderate**: Should review
- **Minor**: Nice to fix

## Best Practices

### Writing New Tests

1. **Use semantic selectors**: Prefer role-based and text-based selectors
2. **Wait for content**: Always use `waitForPageLoad()` before assertions
3. **Test across browsers**: Run in at least Chromium and Firefox
4. **Check WCAG level**: Default to Level AA, use AAA for enhanced features
5. **Document failures**: Include descriptive error messages

### Example Test Pattern

```typescript
test('should have accessible feature', async ({ page }) => {
  // 1. Navigate and wait
  await page.goto(TEST_URLS.myPage);
  await waitForPageLoad(page);

  // 2. Run axe scan
  const results = await scanAccessibility(page, {
    wcagLevel: WCAGLevel.AA,
  });

  // 3. Assert no violations
  expect(
    results.violations,
    `Found violations:\n${formatViolations(results.violations)}`
  ).toHaveLength(0);

  // 4. Test specific interactions
  const button = page.locator('button:has-text("Submit")');
  await expect(button).toBeVisible();
  await expect(button).toBeEnabled();
});
```

### Debugging Failed Tests

1. **Run in headed mode**: `npx playwright test --headed`
2. **Use debug mode**: `npx playwright test --debug`
3. **Check screenshots**: Look in `test-results/`
4. **Enable trace**: View trace in Playwright trace viewer
5. **Isolate test**: Run single test file or use `-g` flag

### CI/CD Integration

Add to your CI pipeline:

```yaml
- name: Run E2E Tests
  run: npx playwright test

- name: Upload Test Results
  if: always()
  uses: actions/upload-artifact@v3
  with:
    name: playwright-report
    path: playwright-report/
```

## Common Issues

### Self-signed Certificate Errors

✅ Handled by `ignoreHTTPSErrors: true` in config

### 401 Unauthorized Errors from Auth Service

✅ **Automatically handled** - The test suite automatically suppresses expected 401 responses from authentication endpoints. These are normal for public pages accessed by unauthenticated users.

The `setupAuthErrorHandling()` function:
- Filters out auth-related console errors
- Allows 401 responses from `/auth`, `/login`, `/user` endpoints
- Only logs unexpected request failures

**Why this happens**: The application checks if users are authenticated on every page load. For public pages accessed during testing (without login), these auth checks return 401, which is expected behavior.

**No action needed** - These errors are automatically handled and won't cause test failures.

### Timeouts

Increase timeout in test:

```typescript
test('long-running test', async ({ page }) => {
  test.setTimeout(120000); // 2 minutes
});
```

### Dynamic Content

Use proper waits:

```typescript
await page.waitForSelector('button:has-text("Loaded")');
await page.waitForLoadState('networkidle');
await waitForPageLoad(page, 15000);
```

### False Positives

Disable specific rules if needed:

```typescript
const results = await scanAccessibility(page, {
  wcagLevel: WCAGLevel.AA,
  disableRules: ['duplicate-id'], // Only if truly needed
});
```

## Resources

- [Playwright Documentation](https://playwright.dev)
- [axe-core Rules](https://github.com/dequelabs/axe-core/blob/develop/doc/rule-descriptions.md)
- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [Accessible Rich Internet Applications (ARIA)](https://www.w3.org/WAI/ARIA/apg/)

## Maintenance

### Updating Tests

When adding new features:

1. Add new test file in `e2e/`
2. Use existing helpers from `helpers/accessibility.ts`
3. Add URLs to `fixtures/test-data.ts`
4. Run tests locally before committing
5. Update this README if needed

### Updating Dependencies

```bash
# Update Playwright
yarn upgrade @playwright/test

# Update axe-core
yarn upgrade @axe-core/playwright axe-core

# Install new browser binaries
npx playwright install
```

---

**Last Updated**: 2025-10-20
**Test Framework**: Playwright 1.56.1
**Accessibility Engine**: axe-core 4.11.0
**WCAG Version**: 2.1 Level AA
