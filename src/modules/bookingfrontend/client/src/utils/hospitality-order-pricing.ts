import type {IHospitalityArticle, IHospitalityOrderLine} from '@/service/types/api/hospitality.types';

/*
 * The price an article of a catering order is shown and summed at in the catering dialog (operator ruling D5 = a: lock what
 * was ordered). A line the order already holds keeps the unit price and VAT it was ordered at, whatever the menu says now:
 * the server keeps that stored price and VAT too (HospitalityOrderController::updateOrder, matched by article id). Only an
 * article the order does not hold takes today's menu price.
 *
 * Types only: the TS harness (tests/catering-keep-price/run.mts) loads this file with no alias resolution, so it imports
 * types and nothing else.
 */

/** What a line is priced with: EX VAT per unit as a decimal string, and the VAT % (null = unknown, not 0 %). */
export interface OrderedPrice {
    effective_price: string;
    effective_tax_percent: number | null;
}

/**
 * The price of one article of the order. A stored line of the article wins, even when the article is no longer on the menu,
 * so the total still equals what the order holds. Null when the order holds no such line and the menu has no such article.
 */
export const orderedPriceOf = (
    articleId: number,
    menuArticles: ReadonlyArray<IHospitalityArticle>,
    storedLines: ReadonlyArray<IHospitalityOrderLine>
): OrderedPrice | null => {
    const stored = storedLines.find(line => line.hospitality_article_id === articleId);
    if (stored) {
        return {effective_price: stored.unit_price, effective_tax_percent: stored.tax_percent};
    }
    const article = menuArticles.find(a => a.id === articleId);
    return article
        ? {effective_price: article.effective_price, effective_tax_percent: article.effective_tax_percent}
        : null;
};

/** Every article with a quantity above 0, with the price it is summed at and that quantity, in the order of `quantities`. */
export const orderedPriceLines = (
    quantities: Readonly<Record<number, number>>,
    menuArticles: ReadonlyArray<IHospitalityArticle>,
    storedLines: ReadonlyArray<IHospitalityOrderLine>
): Array<{price: OrderedPrice; quantity: number}> =>
    Object.entries(quantities).flatMap(([id, quantity]) => {
        const price = quantity > 0 ? orderedPriceOf(Number(id), menuArticles, storedLines) : null;
        return price ? [{price, quantity}] : [];
    });

/**
 * One row of the dialog for an ordered article: every article with a quantity above 0, on the menu or not. The total of the
 * dialog is the sum of these rows, so a line the applicant is charged for is always a line the dialog lists. A row whose article
 * is no longer on the menu is read-only in its price, and it is removed by taking its quantity to 0, like any line.
 */
export interface OrderedDialogRow {
    articleId: number;
    /** The article's name on the menu, else the stored line's, else the article id. */
    name: string;
    quantity: number;
    /** False when the article is no longer on the menu: the row is shown read-only. */
    onMenu: boolean;
    price: OrderedPrice;
}

export const orderedDialogRows = (
    quantities: Readonly<Record<number, number>>,
    menuArticles: ReadonlyArray<IHospitalityArticle>,
    storedLines: ReadonlyArray<IHospitalityOrderLine>
): OrderedDialogRow[] =>
    Object.entries(quantities).flatMap(([id, quantity]) => {
        const articleId = Number(id);
        const price = quantity > 0 ? orderedPriceOf(articleId, menuArticles, storedLines) : null;
        if (!price) return [];
        const menuArticle = menuArticles.find(a => a.id === articleId);
        const stored = storedLines.find(line => line.hospitality_article_id === articleId);
        const name = menuArticle?.article_name ?? stored?.article_name ?? String(articleId);
        return [{articleId, name, quantity, onMenu: menuArticle !== undefined, price}];
    });
