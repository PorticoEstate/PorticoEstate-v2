/**
 * Test data for E2E and accessibility tests
 */

export const TEST_URLS = {
  // Base paths - use relative paths (no leading slash) to work with Playwright baseURL
  base: '',

  // Home/Front page
  frontPage: {
    no: 'no/',
    en: 'en/',
    nn: 'nn/',
  },

  // Search pages
  search: {
    organization: {
      no: 'no/search/organization',
      en: 'en/search/organization',
    },
    event: {
      no: 'no/search/event',
      en: 'en/search/event',
    },
  },

  // Building pages for testing
  buildings: {
    // Building 10 - Fana kulturhus (has timeslot view)
    building10: {
      no: 'no/building/10',
      en: 'en/building/10',
    },
    // Building 46 - Fløyen friluftsområde (Tubakuba)
    building46: {
      no: 'no/building/46',
      en: 'en/building/46',
    },
  },

  // Resource pages for testing
  resources: {
    // Resource 452 - TUBAKUBA (gropsal) in building 46
    resource452: {
      no: 'no/resource/452',
      en: 'en/resource/452',
    },
    // Resource 482 in building 10
    resource482: {
      no: 'no/resource/482',
      en: 'en/resource/482',
    },
  },
};

export const TEST_IDS = {
  buildings: {
    fanaKulturhus: 10,
    floyenFriluftsomrade: 46,
  },
  resources: {
    tubakubaGropsal: 452,
    resource482: 482,
  },
};

export const LANGUAGES = {
  norwegian: 'no',
  english: 'en',
  nynorsk: 'nn',
};

/**
 * Common selectors used across tests
 */
export const SELECTORS = {
  // Navigation
  header: 'header',
  logo: 'a[href*="logo"]',
  languageSwitcher: 'button[aria-label*="språk"], button[aria-label*="language"]',
  cartButton: 'button:has-text("Handlekurv"), button:has-text("Cart")',
  loginButton: 'button:has-text("Logg inn"), button:has-text("Login")',

  // Search/Filter
  searchInput: 'input[type="search"], input[placeholder*="Søk"], input[placeholder*="Search"]',
  dateInput: 'input[placeholder*="dato"], input[placeholder*="date"]',
  locationSelect: 'select, [role="combobox"]',
  filterButton: 'button:has-text("Flere filter"), button:has-text("More filters")',

  // Tabs
  tabList: '[role="tablist"]',
  tab: '[role="tab"]',
  tabPanel: '[role="tabpanel"]',

  // Building page
  buildingHeader: 'h2',
  buildingPhotos: 'img[alt*="kulturhus"], img[alt*="Tubakuba"]',
  accordionButton: 'button[aria-expanded]',

  // Calendar
  calendarWrapper: '[class*="calendar"]',
  calendarView: 'button:has-text("Dag"), button:has-text("Uke"), button:has-text("Måned")',
  timeslotResources: 'text=Tidsslot ressurser',
  calendarResources: 'text=Kalender ressurser',
  resourceCheckbox: 'input[type="checkbox"]',
  newApplicationButton: 'button:has-text("Ny søknad")',

  // Footer
  footer: 'footer',
  contactLinks: 'a[href*="@bergen.kommune.no"]',
  privacyLink: 'a:has-text("Personvern"), a:has-text("Privacy")',
  accessibilityStatement: 'a:has-text("Tilgjengeleghetserklæring"), a:has-text("Accessibility")',
};

/**
 * Expected page titles for validation
 */
export const PAGE_TITLES = {
  frontPage: 'BkBygg',
  building10: 'Fana kulturhus',
  building46: 'Fløyen friluftsområde (Tubakuba)',
  resource452: 'TUBAKUBA (gropsal)',
};

/**
 * Accessibility test configuration
 */
export const ACCESSIBILITY_CONFIG = {
  // Rules to disable globally (if needed)
  disableRules: [] as string[],

  // Specific elements to exclude from all scans
  globalExcludes: [
    '#tanstack-query-devtools-btn', // Dev tools
  ],

  // Timeout settings
  timeout: {
    pageLoad: 10000,
    networkIdle: 5000,
    animation: 500,
  },
};

/**
 * WCAG Success Criteria priorities for testing
 */
export const WCAG_PRIORITIES = {
  critical: [
    'wcag2a', // Level A compliance (mandatory)
    'wcag2aa', // Level AA compliance (recommended)
  ],
  enhanced: [
    'wcag2aaa', // Level AAA compliance (enhanced)
  ],
};
