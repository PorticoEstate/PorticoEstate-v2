# Shopping Cart and Application Creation Tests

## Overview

Comprehensive E2E tests for shopping cart functionality and application creation workflow, including verification of the ToastProvider bug fix.

## Test File

`e2e/shopping-cart.spec.ts`

## What's Tested

### 1. Unauthenticated User Flow
- ✅ Shopping cart hidden when empty
- ✅ "Ny søknad" button visible and functional
- ✅ Can open new application dialog
- ✅ Shows login prompt for recurring bookings

### 2. Authenticated User - Shopping Cart Workflow
- ✅ User authentication verification
- ✅ Create application and add to cart
- ✅ Open shopping cart drawer
- ✅ **Edit application in cart (bug fix verification)**
- ✅ Delete application from cart
- ✅ Accessibility compliance
- ✅ Close shopping cart drawer
- ✅ Navigate to checkout

### 3. Application Creation via API
- ✅ Create application via REST API
- ✅ Verify application appears in UI cart
- ✅ Integration between API and UI

### 4. Recurring Applications (Organization)
- ✅ Recurring toggle visibility for org users
- ✅ Enable recurring booking
- ✅ Organization selector
- ✅ Repeat interval configuration

## Critical Bug Fix Verification

The test suite specifically verifies the fix for the **ToastProvider context bug**:

### The Bug
```
Error: useToast must be used within a ToastProvider
```

This occurred when clicking "Rediger" (Edit) on applications in the shopping cart because the `ApplicationCrud` component was rendered outside the `ToastProvider` context.

### The Fix
Moved `ToastProvider` higher in the provider hierarchy in `src/app/providers.tsx`:

```tsx
// Before (broken):
<ShoppingCartProvider>
  <ToastProvider>
    {children}
  </ToastProvider>
</ShoppingCartProvider>

// After (fixed):
<ToastProvider>
  <ShoppingCartProvider>
    {children}
  </ShoppingCartProvider>
</ToastProvider>
```

### Test Verification
The test `should be able to edit application in shopping cart (fix verification)` specifically:
1. Opens the shopping cart
2. Clicks the "Rediger" button
3. Verifies the edit dialog opens without crashing
4. Monitors console for ToastProvider errors
5. Asserts no errors occurred

## Running the Tests

```bash
# Run all shopping cart tests
npx playwright test e2e/shopping-cart.spec.ts

# Run in specific browser
npx playwright test e2e/shopping-cart.spec.ts --project=chromium

# Run in headed mode (see the browser)
npx playwright test e2e/shopping-cart.spec.ts --headed

# Run specific test
npx playwright test e2e/shopping-cart.spec.ts -g "edit application"

# Debug mode
npx playwright test e2e/shopping-cart.spec.ts --debug
```

## Test Authentication

The tests use **dynamic session authentication** using the `/user/session?force_new=1` endpoint:

```typescript
async function getAuthenticatedSession(page: any) {
  // Get a fresh unauthenticated session via API
  const response = await page.request.get('http://pe-api.test/bookingfrontend/user/session?force_new=1');
  const data = await response.json(); // Returns: {"sessionId":"abc123..."}
  const sessionId = data.sessionId;

  // Set the session cookie
  await page.context().addCookies([{
    name: 'bookingfrontendsession',
    value: sessionId,
    domain: 'pe-api.test',
    path: '/',
  }]);

  // Now login with this session
  await page.goto(TEST_AUTH.loginUrl);
  await page.waitForLoadState('networkidle');

  return sessionId;
}
```

This approach:
- ✅ Gets a fresh, clean session for each test run via API
- ✅ No hardcoded session IDs that expire
- ✅ Session is created unauthenticated, then authenticated via login flow
- ✅ More reliable for CI/CD pipelines
- ✅ Mimics the real session management system

## Test Data

### Resource Used
- **Resource 482**: Anretning (in Fana kulturhus - Building 10)

### Application Data Structure
```typescript
{
  building_id: 10,
  building_name: "Fana kulturhus",
  dates: [{ from_: "ISO_DATE", to_: "ISO_DATE" }],
  audience: [1],
  agegroups: [...],
  articles: [],
  organizer: "Test User",
  name: "Test Application",
  resources: [482],
  activity_id: 1
}
```

## Test Fixtures Updated

Added to `e2e/fixtures/test-data.ts`:

### TEST_AUTH
```typescript
export const TEST_AUTH = {
  loginUrl: 'https://pe-api.test/bookingfrontend/login/?after=%2Fclient%2Fno%2Fresource%2F482',
  logoutUrl: 'https://pe-api.test/bookingfrontend/logout/',
};
```

### SELECTORS (Shopping Cart)
```typescript
shoppingCartFab: 'button:has-text("Handlekurv")',
shoppingCartBadge: '[data-color="brand3"]',
shoppingCartDrawer: 'h2:has-text("Søknader klar for innsending")',
editApplicationButton: 'button:has-text("Rediger")',
deleteApplicationButton: 'button:has-text("Fjern søknad")',
submitApplicationButton: 'a:has-text("Send inn søknad")',
closeDrawerButton: 'button:has-text("Lukk")',
```

## Integration with create_test_applications.sh

The API creation tests are based on the `test_scripts/create_test_applications.sh` script but adapted for Playwright's request API. The test:

1. Creates an application via POST to `/bookingfrontend/applications/partials`
2. Navigates to the resource page
3. Verifies the application appears in the shopping cart

## Accessibility Testing

Shopping cart drawer is tested for WCAG 2.1 Level AA compliance:

```typescript
const results = await scanAccessibility(page, {
  wcagLevel: WCAGLevel.AA,
});
```

## CI/CD Integration

Add to your CI pipeline:

```yaml
- name: Run Shopping Cart Tests
  run: npx playwright test e2e/shopping-cart.spec.ts

- name: Upload Test Results
  if: always()
  uses: actions/upload-artifact@v3
  with:
    name: shopping-cart-test-results
    path: |
      playwright-report/
      test-results/
```

## Troubleshooting

### Tests Fail Due to Authentication
The tests now dynamically obtain a session by logging in. If authentication fails:
- Ensure the login endpoint is accessible: `https://pe-api.test/bookingfrontend/login/`
- Check that the test user credentials are configured in your environment
- Verify cookies are being set correctly after login

### Application Not Appearing in Cart
- Ensure the session is valid and authenticated
- Check that resource 482 exists in your test environment
- Verify WebSocket is working (check console logs)

### Edit Dialog Not Opening
This was the original bug! If this fails, it means:
- The ToastProvider fix was not applied correctly
- Check `src/app/providers.tsx` provider order
- Ensure ToastProvider wraps ShoppingCartProvider

## Related Files

- `src/app/providers.tsx` - Provider hierarchy fix
- `src/components/building-calendar/modules/event/edit/application-crud.tsx` - Uses useToast
- `src/components/toast/toast-context.tsx` - ToastProvider definition
- `src/components/layout/header/shopping-cart/shopping-cart-drawer-component.tsx` - Shopping cart drawer
- `test_scripts/create_test_applications.sh` - API testing reference

## Future Improvements

- [ ] Add tests for combined applications (parent/child)
- [ ] Add tests for checkout flow completion
- [ ] Add tests for file uploads in applications
- [ ] Add tests for article/tileggstjenester selection
- [ ] Add tests for multiple resource selection
- [ ] Add mobile viewport testing for shopping cart FAB

---

**Last Updated**: 2025-11-05
**Test Framework**: Playwright 1.56.1
**Related Fix**: ToastProvider context bug (Shopping cart edit crash)
