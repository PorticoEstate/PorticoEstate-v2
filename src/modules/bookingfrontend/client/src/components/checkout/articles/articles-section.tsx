'use client';
import React, {FC} from 'react';
import {Heading, Table} from '@digdir/designsystemet-react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IApplication, IOrderLine} from '@/service/types/api/application.types';
import {formatCurrency} from '@/utils/cost-utils';
import styles from './articles-section.module.scss';

interface ArticlesSectionProps {
    applications: IApplication[];
}

interface ArticleLine {
    name: string;
    quantity: number;
    total: number;
    currency: string;
    isAddOn: boolean;
}

// Article lines ordered with an application.
// article_cat_id 2 = service (add-on article) — always shown, even when free;
// article_cat_id 1 = the resource rental itself — shown only when it has a price.
// The API serves numeric fields as strings ("125.00"), so coerce before calculating.
const getArticleLines = (application: IApplication): ArticleLine[] =>
    (application.orders ?? [])
        .flatMap(order => order.lines ?? [])
        .map((line: IOrderLine) => ({
            name: line.name,
            quantity: Number(line.quantity),
            total: Number(line.amount) + Number(line.tax),
            currency: line.currency,
            isAddOn: Number(line.article_cat_id) === 2,
        }))
        .filter(line => line.isAddOn || line.total > 0);

const ArticlesSection: FC<ArticlesSectionProps> = ({applications}) => {
    const t = useTrans();

    const groups = applications
        .map(app => ({id: app.id, title: app.name, lines: getArticleLines(app)}))
        .filter(group => group.lines.length > 0);

    if (groups.length === 0) {
        return null;
    }

    const articlesTotal = groups
        .flatMap(group => group.lines)
        .reduce((sum, line) => sum + line.total, 0);

    return (
        <div className={styles.articlesSection}>
            <div className={styles.sectionHeader}>
                <Heading level={3} data-size="xs">{t('bookingfrontend.articles')}</Heading>
            </div>

            <Table data-size="md">
                <Table.Head>
                    <Table.Row>
                        <Table.HeaderCell>{t('bookingfrontend.article')}</Table.HeaderCell>
                        <Table.HeaderCell>{t('booking.quantity')}</Table.HeaderCell>
                        <Table.HeaderCell>{t('booking.sum')}</Table.HeaderCell>
                    </Table.Row>
                </Table.Head>
                <Table.Body>
                    {groups.map(group => (
                        <React.Fragment key={group.id}>
                            <Table.Row className={styles.groupRow}>
                                <Table.Cell colSpan={3}>{group.title}</Table.Cell>
                            </Table.Row>
                            {group.lines.map((line, index) => (
                                <Table.Row key={index}>
                                    <Table.Cell className={styles.lineName}>{line.name}</Table.Cell>
                                    <Table.Cell>{line.quantity}</Table.Cell>
                                    <Table.Cell>
                                        {line.total > 0 ? formatCurrency(line.total, line.currency) : '-'}
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </React.Fragment>
                    ))}
                </Table.Body>
            </Table>

            {articlesTotal > 0 && (
                <div className={styles.articlesTotal}>
                    <span>{t('bookingfrontend.articles')} {t('bookingfrontend.total').toLowerCase()}:</span>
                    <span>{formatCurrency(articlesTotal)}</span>
                </div>
            )}
        </div>
    );
};

export default ArticlesSection;
