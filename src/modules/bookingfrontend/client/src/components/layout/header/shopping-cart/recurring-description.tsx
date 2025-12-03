import React, { FC } from 'react';
import { DateTime } from 'luxon';
import { IApplication } from "@/service/types/api/application.types";
import { RecurringInfoUtils } from '@/utils/recurring-utils';
import { useBuildingSeasons } from "@/service/hooks/api-hooks";
import { useClientTranslation } from "@/app/i18n/ClientTranslationProvider";
import styles from "./shopping-cart-card-list.module.scss";

interface RecurringDescriptionProps {
    application: IApplication;
}

const RecurringDescription: FC<RecurringDescriptionProps> = ({ application }) => {
    const { t } = useClientTranslation();
    const { data: seasons } = useBuildingSeasons(application.building_id);
    
    const recurringInfo = RecurringInfoUtils.parse(application.recurring_info);
    
    if (!recurringInfo) {
        return null;
    }
    
    const interval = recurringInfo.field_interval || 1;
    
    let intervalText = '';
    if (interval === 1) {
        intervalText = t('bookingfrontend.every_week');
    } else {
        intervalText = t('bookingfrontend.every_n_weeks', { n: interval });
    }
    
    let endText = '';
    let seasonName = '';
    
    if (recurringInfo.repeat_until) {
        const endDate = DateTime.fromISO(recurringInfo.repeat_until);
        endText = t('bookingfrontend.until_date', {
            date: endDate.toFormat('dd.MM.yyyy')
        });
    } else if (recurringInfo.outseason) {
        endText = t('bookingfrontend.until_end_of_season');
        
        // Find the current season to show season name
        if (seasons && seasons.length > 0) {
            const startDateTime = DateTime.fromISO(application.dates[0]?.from_ || '');
            const currentSeason = seasons.find(season => {
                const seasonStart = DateTime.fromISO(season.from_);
                const seasonEnd = DateTime.fromISO(season.to_);
                return startDateTime >= seasonStart.startOf('day') && startDateTime <= seasonEnd.endOf('day');
            });
            
            if (currentSeason) {
                seasonName = currentSeason.name || '';
            }
        }
    } else {
        endText = t('bookingfrontend.indefinitely');
    }
    
    return (
        <span>
            {intervalText} {endText}
            {seasonName && <span className={styles.seasonName}> ({seasonName})</span>}
        </span>
    );
};

export default RecurringDescription;