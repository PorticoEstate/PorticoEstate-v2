import React from 'react';
import { DateTime } from 'luxon';
import styles from './debug-info.module.scss';
import {useEnabledResources} from "@/components/building-calendar/calendar-context";
import {Season} from "@/service/types/Building";

interface DebugInfoProps {
    currentDate: DateTime;
    seasons: Season[];
    view: string;
}
const formatSeasonBoundary = (boundary: string) => {
	return DateTime.fromISO(boundary).toFormat('HH:mm:ss');
};

const DebugInfo = ({ currentDate, seasons, view }: DebugInfoProps) => {
	const {enabledResources} = useEnabledResources();

    if (process.env.NODE_ENV !== 'development') {
        return null;
    }

	const getCurrentWeekSeasons = () => {
		const weekStart = currentDate.startOf('week');
		const weekSeasons: string[] = [];

		for (let i = 0; i < 7; i++) {
			const currentDay = weekStart.plus({ days: i });
			const dayOfWeek = currentDay.weekday; // Luxon uses 1=Monday to 7=Sunday

			// Find seasons whose boundaries include the matching `wday`
			const matchingSeasons = seasons.filter(season =>
				season.boundaries.some(boundary => boundary.wday === (dayOfWeek === 7 ? 0 : dayOfWeek))
			);

			if (matchingSeasons.length > 0) {
				matchingSeasons.forEach(season => {
					// For each matching season, format the boundary times and display
					const boundary = season.boundaries.find(boundary => boundary.wday === (dayOfWeek === 7 ? 0 : dayOfWeek));
					if (boundary) {
						const fromFormatted = formatSeasonBoundary(boundary.from_);
						const toFormatted = formatSeasonBoundary(boundary.to_);
						weekSeasons.push(`${currentDay.weekdayLong}: ${fromFormatted} - ${toFormatted}`);
					}
				});
			}
		}

		return weekSeasons;
	};

    return (
        <div className={styles.debugContainer}>
            <div className={styles.debugHeader}>
                Debug Info:
            </div>
            <div className={styles.debugGrid}>
                <div>
                    <div className={styles.debugInfo}>Current View: {view}</div>
                    <div className={styles.debugInfo}>Current Date: {currentDate.toFormat('yyyy-MM-dd')}</div>
                    <div className={styles.debugInfo}>Week Number: {currentDate.weekNumber}</div>
                    <div className={styles.debugInfo}>Enabled Resources: {enabledResources.size}</div>
                </div>
                <div>
                    <div className={styles.seasonHeader}>Week Seasons:</div>
                    <div className={styles.seasonsList}>
                        {getCurrentWeekSeasons().map((season, index) => (
                            <div key={index} className={styles.debugInfo}>{season}</div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default DebugInfo;