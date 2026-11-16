import type {ArticleOrder, IArticle} from '@/service/types/api/order-articles.types';

/**
 * Which order lines belong to which resource, so that the lines of a resource the applicant
 * takes out of the application go with it (pe-backlog/36753).
 *
 * What belongs to a resource is read from the offer's own fields, never from names:
 * - its resource article (`resource_id` set), and
 * - the add-ons that point at it (`parent_mapping_id` = that article's id, followed up the chain).
 *
 * An add-on that a resource which stays also offers (the same mapping id under another parent)
 * stays too. A line whose article is not in the offer at all is not guessed at and stays.
 */

/** The resource an offered article belongs to: its own, or its parent's up the chain */
const resourceOf = (article: IArticle, byId: Map<number, IArticle>): number | null => {
	let current: IArticle | undefined = article;
	const seen = new Set<number>();
	while (current && !seen.has(current.id)) {
		if (current.resource_id) {
			return current.resource_id;
		}
		seen.add(current.id);
		current = current.parent_mapping_id ? byId.get(current.parent_mapping_id) : undefined;
	}
	return null;
};

/**
 * The ids of the articles (resource articles and add-ons) to take out of the order when the
 * given resources are removed: everything the removed resources offer, minus whatever a
 * remaining resource also offers.
 *
 * @param offer the articles offered for the resources chosen BEFORE the change
 * @param remainingResourceIds the resources that stay chosen
 */
export const articleIdsOfRemovedResources = (offer: IArticle[], remainingResourceIds: number[]): Set<number> => {
	const byId = new Map<number, IArticle>(offer.map(article => [article.id, article]));
	const remaining = new Set(remainingResourceIds);
	const removed = new Set<number>();
	const kept = new Set<number>();

	offer.forEach(article => {
		const resourceId = resourceOf(article, byId);
		if (resourceId === null) {
			return;
		}
		(remaining.has(resourceId) ? kept : removed).add(article.id);
	});

	kept.forEach(id => removed.delete(id));
	return removed;
};

/**
 * The order lines left when the chosen resources become `remainingResourceIds`: the lines of the
 * removed resources are dropped, with their price choice, and every other line is returned as it was.
 * The same array is returned when nothing is dropped.
 *
 * @param offer the articles offered for the resources chosen BEFORE the change; without it
 *              (still loading) nothing is dropped, and the server guard is the backstop
 */
export const withoutRemovedResourceLines = (
	lines: ArticleOrder[],
	offer: IArticle[] | undefined,
	remainingResourceIds: number[]
): ArticleOrder[] => {
	if (!offer || offer.length === 0 || lines.length === 0) {
		return lines;
	}
	const dropIds = articleIdsOfRemovedResources(offer, remainingResourceIds);
	const left = lines.filter(line => !dropIds.has(line.id));
	return left.length === lines.length ? lines : left;
};
