

interface Date {
    /**
     * Returns a string in simplified extended ISO format (ISO 8601)
     */
    toISOString(): TDateISO;
}

// Numeric ranges
type Digit = 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9;
type YYYY = `${19 | 20}${Digit}${Digit}`;
type MM = `0${1|2|3|4|5|6|7|8|9}` | `1${0|1|2}`;
type DD = `0${1|2|3|4|5|6|7|8|9}` | `${1|2}${Digit}` | `3${0|1}`;
type HH = `0${Digit}` | `1${Digit}` | `2${0|1|2|3}`;
type mm = `${0|1|2|3|4|5}${Digit}`;
type ss = mm; // Same range as minutes
type sss = `${Digit}${Digit}${Digit}`;

// Timezone offset
type TimezoneOffset = `+${HH}:${mm}` | `-${HH}:${mm}` | 'Z';

/**
 * Represents a valid ISO 8601 date portion (YYYY-MM-DD)
 */
type TDateISODate = `${YYYY}-${MM}-${DD}`;

/**
 * Represents a valid ISO 8601 time portion, with optional milliseconds
 */
// type TDateISOTime = `${HH}:${mm}:${ss}` | `${HH}:${mm}:${ss}.${sss}`;

/**
 * Represents a complete valid ISO 8601 datetime string
 * Supports both formats:
 * - YYYY-MM-DDTHH:mm:ss.sssZ
 * - YYYY-MM-DDTHH:mm:ss±HH:mm
 */
// type TDateISO = `${TDateISODate}T${TDateISOTime}${TimezoneOffset}`;
type TDateISO = string;