'use client';
import React, {FC} from 'react';
import {Heading, Paragraph, Table} from '@digdir/designsystemet-react';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {IApplication, IOrderLine} from '@/service/types/api/application.types';
import {ArticleOrder, IArticle} from '@/service/types/api/order-articles.types';
import {useResourceArticles, useUpdatePartialApplication} from '@/service/hooks/api-hooks';
import {ResourceUsesTimeSlots} from '@/components/building-calendar/util/calender-helpers';
import PriceChoiceField from '@/components/price-choice/price-choice-field';
import {formatCurrency} from '@/utils/cost-utils';
import styles from './articles-section.module.scss';

interface ArticlesSectionProps {
    applications: IApplication[];
    /** Show the error at a price that still has to be chosen (after a submit attempt) */
    showPriceChoiceErrors?: boolean;
}

interface ArticleLine {
    name: string;
    quantity: number;
    total: number;
    currency: string;
    isAddOn: boolean;
    priceLabel: string | null;
    /** Set when the applicant chooses this room's price here */
    priceChoice: IArticle | null;
    pricePending: boolean;
    index: number;
}

/** The id of a price field in checkout, so a blocked submit can move focus to it */
export const priceChoiceFieldId = (applicationId: number, articleMappingId: number) =>
    `price-choice-${applicationId}-${articleMappingId}`;

/**
 * A room line still waiting for the applicant's price: booked through a time
 * slot, where nobody can choose, for a room with several prices and no
 * default. The server refuses to submit it (ArticleRepository::assertPriceChoicesMade).
 */
const isPricePending = (line: IOrderLine, article?: IArticle) =>
    Number(line.article_cat_id) === 1 &&
    line.article_price_id == null &&
    Number(line.unit_price) === 0 &&
    (article?.price_options?.length ?? 0) > 0;

const applicationLines = (application: IApplication): IOrderLine[] =>
    (application.orders ?? []).flatMap(order => order.lines ?? []);

/**
 * The first room in the cart whose price has yet to be chosen, if any.
 */
export const findPendingPriceChoice = (applications: IApplication[], articles: IArticle[]) => {
    for (const application of applications) {
        for (const line of applicationLines(application)) {
            const article = articles.find(a => a.id === line.article_mapping_id);
            if (isPricePending(line, article)) {
                return {applicationId: application.id, articleMappingId: line.article_mapping_id};
            }
        }
    }
    return null;
};

// Article lines ordered with an application.
// article_cat_id 2 = service (add-on article) — always shown, even when free;
// article_cat_id 1 = the resource rental itself — shown only when it has a price,
// or while its price is still to be chosen.
// The API serves numeric fields as strings ("125.00"), so coerce before calculating.
const getArticleLines = (application: IApplication, articles: IArticle[]): ArticleLine[] => {
    // A time slot is booked without the application form, so the room's price
    // is chosen here instead
    const bookedByTimeslot = (application.resources ?? []).some(resource => ResourceUsesTimeSlots(resource));

    return applicationLines(application)
        .map((line: IOrderLine, index) => {
            const article = articles.find(a => a.id === line.article_mapping_id);
            const optionCount = article?.price_options?.length ?? 0;
            const pricePending = isPricePending(line, article);
            const choosable = Number(line.article_cat_id) === 1 &&
                (pricePending || (bookedByTimeslot && optionCount > 1));

            return {
                name: line.name,
                quantity: Number(line.quantity),
                total: Number(line.amount) + Number(line.tax),
                currency: line.currency,
                isAddOn: Number(line.article_cat_id) === 2,
                priceLabel: line.price_label ?? null,
                priceChoice: choosable && article ? article : null,
                pricePending,
                index,
            };
        })
        .filter(line => line.isAddOn || line.total > 0 || line.priceChoice !== null);
};

const ArticlesSection: FC<ArticlesSectionProps> = ({applications, showPriceChoiceErrors = false}) => {
    const t = useTrans();
    const updateMutation = useUpdatePartialApplication();

    const resourceIds = Array.from(new Set(
        applications.flatMap(app => (app.resources ?? []).map(resource => resource.id))
    ));
    const {data: articles} = useResourceArticles({resourceIds});

    const groups = applications
        .map(app => ({application: app, lines: getArticleLines(app, articles ?? [])}))
        .filter(group => group.lines.length > 0);

    if (groups.length === 0) {
        return null;
    }

    const articlesTotal = groups
        .flatMap(group => group.lines)
        .reduce((sum, line) => sum + line.total, 0);

    // Re-save the application's order with the chosen price; the server prices
    // the line from the price row, never from the client
    const choosePrice = (application: IApplication, lineIndex: number, priceId: number) => {
        const articleOrders: ArticleOrder[] = applicationLines(application).map((line, index) => ({
            id: line.article_mapping_id,
            quantity: Number(line.quantity),
            parent_id: line.parent_mapping_id > 0 ? line.parent_mapping_id : null,
            price_id: index === lineIndex
                ? priceId
                : (Number(line.article_cat_id) === 1 ? line.article_price_id ?? null : null),
        }));
        updateMutation.mutate({
            id: application.id,
            application: {id: application.id, building_id: application.building_id, articles: articleOrders},
        });
    };

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
                    {groups.map(({application, lines}) => (
                        <React.Fragment key={application.id}>
                            <Table.Row className={styles.groupRow}>
                                <Table.Cell colSpan={3}>{application.name}</Table.Cell>
                            </Table.Row>
                            {lines.map(line => (
                                <React.Fragment key={line.index}>
                                    <Table.Row>
                                        <Table.Cell className={styles.lineName}>
                                            {line.name}
                                            {line.priceLabel && !line.priceChoice && (
                                                <Paragraph data-size="sm">
                                                    {t('bookingfrontend.price_category')}: {line.priceLabel}
                                                </Paragraph>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell>{line.quantity}</Table.Cell>
                                        <Table.Cell>
                                            {line.pricePending
                                                ? t('bookingfrontend.price_pending')
                                                : line.total > 0 ? formatCurrency(line.total, line.currency) : '-'}
                                        </Table.Cell>
                                    </Table.Row>
                                    {line.priceChoice && (
                                        <Table.Row>
                                            <Table.Cell colSpan={3}>
                                                <PriceChoiceField
                                                    id={priceChoiceFieldId(application.id, line.priceChoice.id)}
                                                    options={line.priceChoice.price_options ?? []}
                                                    value={applicationLines(application)[line.index].article_price_id ?? null}
                                                    onChange={(priceId) => choosePrice(application, line.index, priceId)}
                                                    quantity={line.quantity}
                                                    disabled={updateMutation.isPending}
                                                    error={showPriceChoiceErrors && line.pricePending
                                                        ? t('bookingfrontend.price_choice_required')
                                                        : undefined}
                                                />
                                            </Table.Cell>
                                        </Table.Row>
                                    )}
                                </React.Fragment>
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
