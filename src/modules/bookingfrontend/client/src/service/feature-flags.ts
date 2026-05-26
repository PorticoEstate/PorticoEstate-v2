/**
 * Feature flags for the bookingfrontend client.
 *
 * These are read-only constants that toggle client-side behavior.
 * They are NOT environment variables — change them here and rebuild.
 */

/**
 * When true, child applications (parent_id != null && parent_id != id) are hidden
 * from the user's application list. The parent application aggregates dates, resources,
 * and other data from all its children into a single combined view.
 *
 * When false, every application is shown individually regardless of parent_id.
 *
 * This mirrors the server-side `combined_applications_mode` config in bb_config.
 */
export const ENABLE_COMBINED_APPLICATIONS = false as const;
