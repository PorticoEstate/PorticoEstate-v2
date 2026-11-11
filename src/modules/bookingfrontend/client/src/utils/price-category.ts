import {ArticleOrder, IArticle, IPriceOption} from '@/service/types/api/order-articles.types';

/**
 * One price category for the whole application (#1477).
 *
 * A resource's prices can each belong to a price category (bb_price_category,
 * served as price_options[].category_id). When the applicant books several
 * resources that each offer a choice of price, and the same categories are
 * found on all of them, the applicant chooses the category once and every
 * resource is priced at its OWN row in that category. Otherwise each resource
 * keeps its own choice, as before.
 *
 * The prices are those the server offers today (price_options as served), the
 * only ones it accepts. The client still sends one price_id per resource; the
 * server works out the category from the row.
 */

/**
 * OPERATOR RULE PENDING (forum/pe-tasks/36718): whether a resource with a single
 * price counts in "every chosen resource". false: it is left out of the match and
 * keeps its one price. true: its single row must be in the category too, so a
 * single price without it means no shared choice.
 */
export const COUNT_SINGLE_PRICE_RESOURCES = false;

export interface SharedCategory {
	id: number;
	name: string;
}

const optionCount = (article: IArticle): number => article.price_options?.length ?? 0;

/** A resource whose price the applicant chooses: it has more than one */
export const hasPriceChoice = (article: IArticle): boolean => optionCount(article) > 1;

/**
 * What a price is called where the applicant chooses between prices: its category's
 * name when it has one, otherwise its remark. With the category hidden (config
 * hide_price_category) it is called by its remark, as before categories; the category's
 * name is then only the fallback for a price with no remark, so that no choice is left
 * a bare amount. Empty when it has neither.
 */
export const priceOptionLabel = (option: IPriceOption, hideCategory: boolean): string =>
	(hideCategory
		? option.remark?.trim() || option.category_name
		: option.category_name || option.remark) || '';

/** The resources a shared category has to be found on */
const participants = (articles: IArticle[]): IArticle[] =>
	articles.filter(article => optionCount(article) > (COUNT_SINGLE_PRICE_RESOURCES ? 0 : 1));

/**
 * The resource's row in the category. A category given to two of its rows does not
 * say which price applies, so the resource is then taken not to have it.
 */
export const optionInCategory = (article: IArticle, categoryId: number): IPriceOption | null => {
	const options = (article.price_options ?? []).filter(option => option.category_id === categoryId);
	return options.length === 1 ? options[0] : null;
};

/**
 * The categories the applicant can choose once for all the given resources: those
 * found on every participating resource, in the order the first one offers them.
 * Empty, so that each resource keeps its own choice, unless at least two of the
 * resources offer a choice.
 */
export const sharedCategories = (articles: IArticle[]): SharedCategory[] => {
	if (articles.filter(hasPriceChoice).length < 2) {
		return [];
	}

	const [first, ...others] = participants(articles);
	return (first.price_options ?? [])
		.filter((option): option is IPriceOption & {category_id: number} =>
			option.category_id != null &&
			optionInCategory(first, option.category_id) !== null &&
			others.every(article => optionInCategory(article, option.category_id as number) !== null))
		.map(option => ({id: option.category_id, name: option.category_name ?? ''}));
};

/**
 * The order with the resource priced at priceId: its line gets the price, or a
 * line is added for it, as a choice made before the line exists
 */
export const withPriceChoice = (articles: ArticleOrder[], article: IArticle, priceId: number): ArticleOrder[] =>
	articles.some(a => a.id === article.id)
		? articles.map(a => a.id === article.id ? {...a, price_id: priceId} : a)
		: [...articles, {id: article.id, quantity: 1, parent_id: article.parent_mapping_id || null, price_id: priceId}];

/**
 * The category the resources are priced in, when they all are in the same one:
 * the applicant's choice, or the defaults when those agree. Null otherwise, also
 * when a resource has no price chosen yet.
 *
 * @param priceIdOf the price in force for a resource (chosen, else its default)
 */
export const categoryInForce = (
	articles: IArticle[],
	priceIdOf: (article: IArticle) => number | null
): number | null => {
	const categories = participants(articles).map(article => {
		const priceId = optionCount(article) === 1 ? article.price_options![0].price_id : priceIdOf(article);
		return article.price_options?.find(option => option.price_id === priceId)?.category_id ?? null;
	});

	if (categories.length === 0 || categories.some(category => category === null)) {
		return null;
	}
	return categories.every(category => category === categories[0]) ? categories[0] : null;
};
