/**
 * Shows the date of birth and hides the personal number: 01019012345 → 010190*****.
 * Used wherever the citizen's own SSN is displayed (profile, checkout billing card).
 */
export const maskSSN = (ssn: string): string => {
    if (!ssn) return '';
    return ssn.slice(0, -5) + '*****';
};
