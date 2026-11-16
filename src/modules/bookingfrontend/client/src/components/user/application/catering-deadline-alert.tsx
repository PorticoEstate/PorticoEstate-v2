'use client';
import React, {FC} from 'react';
import {Alert, Paragraph} from '@digdir/designsystemet-react';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {Lead, LeadUnit, RuleGroup, sentenceOf} from '@/utils/catering-deadline-rule';

/** [plural, singular] lang key of a lead's unit, each with the number as %1. */
const LEAD_KEYS: Record<LeadUnit, [string, string]> = {
    hours: ['catering_overview_lead_hours', 'catering_overview_lead_hour'],
    days: ['catering_overview_lead_days', 'catering_overview_lead_day'],
    weeks: ['catering_overview_lead_weeks', 'catering_overview_lead_week'],
};

interface CateringDeadlineAlertProps {
    groups: RuleGroup[];
}

/**
 * The change / cancel rule, stated once at the top of the section (GH #1423). It explains; it never decides:
 * the buttons below follow the server's `actions`, and this text is built from the offers' configuration
 * (utils/catering-deadline-rule.ts), so where the two could ever disagree the server's answer is the one acted on.
 * One rule for every offer → one paragraph; different rules → one line per group of offers, headed by their names.
 */
const CateringDeadlineAlert: FC<CateringDeadlineAlertProps> = ({groups}) => {
    const {t} = useClientTranslation();
    if (groups.length === 0) return null;

    const lead = ({value, unit}: Lead): string =>
        t(`bookingfrontend.${LEAD_KEYS[unit][value === 1 ? 1 : 0]}`).replace('%1', String(value));

    const sentence = (group: RuleGroup): string => {
        const s = sentenceOf(group.rule);
        const text = s.kind === 'both'
            ? t('bookingfrontend.catering_overview_deadline_both').replace('%1', lead(s.lead))
            : s.kind === 'split'
                ? t('bookingfrontend.catering_overview_deadline_split').replace('%1', lead(s.change)).replace('%2', lead(s.cancel))
                : s.kind === 'split_open'
                    ? t('bookingfrontend.catering_overview_deadline_split_open').replace('%1', lead(s.change))
                    : t('bookingfrontend.catering_overview_deadline_none');
        return group.rule.workingDays ? `${text} ${t('bookingfrontend.catering_overview_deadline_working_days')}` : text;
    };

    return (
        <Alert data-color="info" data-size="sm" data-testid="catering-deadline-rule">
            {groups.map(group => (
                <Paragraph key={group.offerNames.join('|')} data-size="sm">
                    {groups.length > 1 && <strong>{group.offerNames.join(', ')}: </strong>}
                    {sentence(group)}
                </Paragraph>
            ))}
        </Alert>
    );
};

export default CateringDeadlineAlert;
