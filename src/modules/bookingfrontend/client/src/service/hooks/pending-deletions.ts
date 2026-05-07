/**
 * Track application IDs that are pending deletion.
 * Prevents server-pushed partial_applications_response from re-adding
 * items that the user has already deleted but the server hasn't confirmed yet.
 */
export const pendingDeletions = new Set<number>();
