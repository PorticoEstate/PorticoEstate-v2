import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for WCAG/Accessibility testing
 * Testing the Bookingfrontend client application
 */
export default defineConfig({
  testDir: './e2e',

  // Maximum time one test can run (increased for slow page loads)
  timeout: 120 * 1000,

  // Test execution settings
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,

  // Reporter configuration
  reporter: [
    ['html', { outputFolder: 'playwright-report' }],
    ['json', { outputFile: 'test-results/results.json' }],
    ['list']
  ],

  // Shared settings for all projects
  use: {
    // Base URL for the application (must end with trailing slash for relative paths to work correctly)
    baseURL: 'https://pe-api.test/bookingfrontend/client/',

    // Collect trace when retrying failed tests
    trace: 'on-first-retry',

    // Screenshot on failure
    screenshot: 'only-on-failure',

    // Video on first retry
    video: 'retain-on-failure',

    // Ignore HTTPS errors (for local development with self-signed certs)
    ignoreHTTPSErrors: true,

    // Default timeout for actions
    actionTimeout: 15000,

    // Default navigation timeout (increased for slow page loads with auth checks)
    navigationTimeout: 60000,
  },

  // Configure projects for different browsers
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },

    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },

    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },

    // Mobile viewports for responsive testing
    {
      name: 'Mobile Chrome',
      use: { ...devices['Pixel 5'] },
    },

    {
      name: 'Mobile Safari',
      use: { ...devices['iPhone 12'] },
    },
  ],

  // Output folder for test artifacts
  outputDir: 'test-results/',
});
