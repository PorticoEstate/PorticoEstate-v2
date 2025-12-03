import { DateTime } from 'luxon';
import { IApplication, RecurringInfoUtils as OriginalRecurringInfoUtils } from '@/service/types/api/application.types';
import { Season } from '@/service/types/Building';

// Re-export RecurringInfoUtils for convenience
export const RecurringInfoUtils = OriginalRecurringInfoUtils;

export interface RecurringInstance {
    start: DateTime;
    end: DateTime;
    weekOffset: number; // Which week this is (0 = original, 1 = first repeat, etc.)
}

/**
 * Calculate all recurring instances for an application
 */
export function calculateRecurringInstances(
    application: IApplication,
    seasons?: Season[]
): RecurringInstance[] {
    const recurringInfo = OriginalRecurringInfoUtils.parse(application.recurring_info);

    if (!recurringInfo || !application.dates || application.dates.length === 0) {
        return [];
    }

    const instances: RecurringInstance[] = [];
    const baseDate = application.dates[0]; // Use first date as template
    const startDateTime = DateTime.fromISO(baseDate.from_);
    const endDateTime = DateTime.fromISO(baseDate.to_);
    const duration = endDateTime.diff(startDateTime);

    // Add the original instance
    instances.push({
        start: startDateTime,
        end: endDateTime,
        weekOffset: 0
    });

    const interval = recurringInfo.field_interval || 1; // Default to weekly
    let currentWeekOffset = interval;

    // Determine end date for repetition
    let repeatUntilDate: DateTime;

    if (recurringInfo.repeat_until) {
        repeatUntilDate = DateTime.fromISO(recurringInfo.repeat_until).endOf('day');
    } else {
        // Find current season end - look for season that contains or comes after the start date
        if (seasons && seasons.length > 0) {
            // Sort seasons by start date and find the most appropriate one
            const sortedSeasons = seasons
                .filter(season => season.active)
                .sort((a, b) => DateTime.fromISO(a.from_).toMillis() - DateTime.fromISO(b.from_).toMillis());

            // First try to find season that contains the start date
            let currentSeason = sortedSeasons.find(season => {
                const seasonStart = DateTime.fromISO(season.from_);
                const seasonEnd = DateTime.fromISO(season.to_);
                return startDateTime >= seasonStart.startOf('day') && startDateTime <= seasonEnd.endOf('day');
            });

            // If no season contains the start date, use the next upcoming season
            if (!currentSeason) {
                currentSeason = sortedSeasons.find(season => {
                    const seasonStart = DateTime.fromISO(season.from_);
                    return startDateTime <= seasonStart;
                });
            }

            // If still no season found, use the latest season
            if (!currentSeason && sortedSeasons.length > 0) {
                currentSeason = sortedSeasons[sortedSeasons.length - 1];
            }

            if (currentSeason) {
                repeatUntilDate = DateTime.fromISO(currentSeason.to_).endOf('day');
            } else {
                // Default to 6 months if no season found
                repeatUntilDate = startDateTime.plus({ months: 6 });
            }
        } else {
            // Default to 6 months if no seasons provided
            repeatUntilDate = startDateTime.plus({ months: 6 });
        }
    }

    // Generate recurring instances
    let occurrenceNumber = 1; // Start at 1 since 0 is the original
    while (true) {
        const nextStart = startDateTime.plus({ weeks: currentWeekOffset });
        const nextEnd = nextStart.plus(duration);

        // Check if this instance would be beyond the repeat until date
        if (nextStart > repeatUntilDate) {
            break;
        }

        instances.push({
            start: nextStart,
            end: nextEnd,
            weekOffset: occurrenceNumber
        });

        currentWeekOffset += interval;
        occurrenceNumber++;

        // Safety limit to prevent infinite loops
        if (instances.length > 100) {
            console.warn('Recurring instances limit reached (100), stopping generation');
            break;
        }
    }

    return instances;
}

/**
 * Get a human-readable description of the recurring pattern
 */
export function getRecurringDescription(
    application: IApplication,
    seasons?: Season[],
    t?: (key: string, params?: any) => string
): string {
    const recurringInfo = OriginalRecurringInfoUtils.parse(application.recurring_info);

    if (!recurringInfo) {
        return '';
    }

    const tFallback = (key: string, params?: any) => {
        // Fallback translations
        const translations: Record<string, string> = {
            'bookingfrontend.every_week': 'Every week',
            'bookingfrontend.every_n_weeks': `Every ${params?.n} weeks`,
            'bookingfrontend.until_date': `until ${params?.date}`,
            'bookingfrontend.until_end_of_season': 'until end of season',
            'bookingfrontend.indefinitely': 'indefinitely'
        };
        return translations[key] || key;
    };

    const translate = t || tFallback;
    const interval = recurringInfo.field_interval || 1;

    let intervalText = '';
    if (interval === 1) {
        intervalText = translate('bookingfrontend.every_week');
    } else {
        intervalText = translate('bookingfrontend.every_n_weeks', { n: interval });
    }

    let endText = '';
    if (recurringInfo.outseason) {
        endText = translate('bookingfrontend.indefinitely');
    } else if (recurringInfo.repeat_until) {
        const endDate = DateTime.fromISO(recurringInfo.repeat_until);
        endText = translate('bookingfrontend.until_date', {
            date: endDate.toFormat('dd.MM.yyyy')
        });
    } else {
        endText = translate('bookingfrontend.until_end_of_season');
    }

    return `${intervalText} ${endText}`;
}