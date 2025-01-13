import React from 'react';
import { DateTime } from 'luxon';
import { Season } from "@/service/pecalendar.types";
import styles from './debug-info.module.scss';

interface DebugInfoProps {
    currentDate: DateTime;
    seasons: Season[];
    view: string;
    enabledResources: Set<string>;
}

const DebugInfo = ({ currentDate, seasons, view, enabledResources }: DebugInfoProps) => {
    if (process.env.NODE_ENV !== 'development') {
        return null;
    }

    const getCurrentWeekSeasons = () => {
        const weekStart = currentDate.startOf('week');
        const weekSeasons = [];
        for (let i = 0; i < 7; i++) {
            const currentDay = weekStart.plus({ days: i });
            const dayOfWeek = currentDay.weekday;
            const season = seasons.find(s => s.wday === (dayOfWeek === 7 ? 0 : dayOfWeek));

            if (season) {
                weekSeasons.push(`${currentDay.weekdayLong}: ${season.from_}-${season.to_}`);
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