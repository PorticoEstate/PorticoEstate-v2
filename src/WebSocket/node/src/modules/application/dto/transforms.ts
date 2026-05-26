/**
 * Shared transform helpers matching PHP SerializableTrait behavior.
 */

/**
 * Format a timestamp to ISO 8601 in Europe/Oslo timezone.
 * Matches PHP @Timestamp annotation defaults (format="c", sourceTimezone="Europe/Oslo").
 */
export function formatOsloTimestamp(value: any): string | null {
  if (value == null) return null;
  try {
    const date = value instanceof Date ? value : new Date(value);
    if (isNaN(date.getTime())) return String(value);

    // Format date components in Oslo timezone
    const formatted = date.toLocaleString('sv-SE', {
      timeZone: 'Europe/Oslo',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });

    return formatted.replace(' ', 'T') + getOsloOffset(date);
  } catch {
    return String(value);
  }
}

/**
 * Get the UTC offset string for Europe/Oslo at a given date (e.g., "+02:00").
 */
function getOsloOffset(date: Date): string {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Europe/Oslo',
    timeZoneName: 'longOffset',
  }).formatToParts(date);
  const tzPart = parts.find((p) => p.type === 'timeZoneName');
  if (tzPart) {
    const match = tzPart.value.match(/GMT([+-]\d{2}:\d{2})/);
    if (match) return match[1];
  }
  return '+01:00';
}

/**
 * Decode HTML entities matching PHP @EscapeString(mode="default") / sanitizeString().
 */
export function sanitizeString(value: any): string | null {
  if (value == null) return null;
  if (typeof value !== 'string') return value;
  let decoded = value
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&#(\d+);/g, (_, code) => String.fromCharCode(parseInt(code, 10)));
  // Handle double-encoded entities
  if (decoded.includes('&amp;')) {
    decoded = decoded.replace(/&amp;/g, '&');
  }
  return decoded;
}

/**
 * Parse string booleans matching PHP @ParseBool behavior.
 */
export function parseBool(value: any): boolean {
  if (typeof value === 'string') {
    const lower = value.toLowerCase().trim();
    if (['true', 'yes', '1'].includes(lower)) return true;
    return false;
  }
  if (typeof value === 'number') return value === 1;
  if (value === null || value === undefined) return false;
  return !!value;
}
