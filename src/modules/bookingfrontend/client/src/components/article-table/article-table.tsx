// components/article-table/article-table.tsx
import React, {useCallback, useEffect, useMemo} from 'react';
import {Checkbox, Table, Textfield, Spinner, Button} from '@digdir/designsystemet-react';
import {useResourceArticles} from '@/service/hooks/api-hooks';
import {ArticleOrder, IArticle} from '@/service/types/api/order-articles.types';
import {IOrder} from '@/service/types/api/application.types';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {DateTime} from 'luxon';
import {
	bookedDays,
	bookedMinutes,
	formatHourlyRate,
	formatMinutes,
	formatQuantity,
	formatQuantityWithUnit,
	formatTotalWithHourlyRate,
	hourlyRateOfMinuteArticle,
	hourlyRateOfOrderLine,
	isDayUnit,
	isEachUnit,
	isMinuteUnit,
	lineTotalOre,
	minuteArticleTotal,
	orderLineTotal,
	unitPriceInclOfOrderLine
} from '@/utils/article-pricing';
import {formatCurrency} from '@/utils/cost-utils';
import {MinusCircleIcon, PlusCircleIcon} from '@navikt/aksel-icons';
import styles from './article-table.module.scss';

interface ArticleTableProps {
	resourceIds?: number[];
	selectedArticles?: ArticleOrder[];
	orders?: IOrder[];
	onArticlesChange?: (articles: ArticleOrder[]) => void;
	readOnly?: boolean;
	startTime?: Date;
	endTime?: Date;
	mode?: 'articles' | 'orders';
}

const ArticleTable: React.FC<ArticleTableProps> = ({
													   resourceIds = [],
													   selectedArticles = [],
													   orders = [],
													   onArticlesChange,
													   readOnly = false,
													   startTime,
													   endTime,
													   mode = 'articles'
												   }) => {
	const t = useTrans();

	// Calculate the duration in hours (rounded up to the nearest full hour)
	const durationHours = useMemo(() => {
		if (!startTime || !endTime) return 1; // Default to 1 hour if no times provided

		const start = DateTime.fromJSDate(startTime);
		const end = DateTime.fromJSDate(endTime);
		const diffInHours = end.diff(start, 'hours').hours;

		// Round up to the nearest full hour
		return Math.ceil(diffInHours);
	}, [startTime, endTime]);

	// Minute-priced articles bill the exact booked minutes, never the rounded-up hours above
	const durationMinutes = useMemo(() => bookedMinutes(startTime, endTime), [startTime, endTime]);

	// Day-priced articles bill the started 24-hour periods (the legacy forms' rule), at least one
	const durationDays = useMemo(() => bookedDays(startTime, endTime), [startTime, endTime]);

	// The quantity a mandatory resource article is billed at, or null when the quantity is the
	// applicant's own choice. Hourly articles bill whole started hours, minute articles exact minutes,
	// day articles started 24-hour periods and each articles 1 per booked period.
	const mandatoryQuantity = (article: IArticle): number | null => {
		const isMandatoryResource = article.resource_id &&
			(article.mandatory === 1 || article.mandatory === '1');

		if (!isMandatoryResource) {
			return null;
		}
		if (article.unit === 'hour') {
			return durationHours;
		}
		if (isMinuteUnit(article.unit)) {
			return durationMinutes;
		}
		if (isDayUnit(article.unit)) {
			return durationDays;
		}
		if (isEachUnit(article.unit)) {
			return 1;
		}
		// Any other unit (kg, m, m2 ...) has no rule: null, the applicant's own choice
		return null;
	};

	// Create a map for easier lookup of selected articles
	const selectedArticlesMap = useMemo(() => {
		return new Map(selectedArticles?.map(article => [article.id, article]));
	}, [selectedArticles]);

	// A room with several prices costs what the applicant chose (or the default);
	// null while nothing is chosen, so the price is pending rather than 0
	const unitPriceOf = useCallback((article: IArticle): number | null => {
		const options = article.price_options ?? [];
		if (options.length < 2) {
			return parseFloat(article.unit_price);
		}
		const priceId = selectedArticlesMap.get(article.id)?.price_id ?? article.default_price_id;
		const option = options.find(o => o.price_id === priceId);
		return option ? parseFloat(option.price) : null;
	}, [selectedArticlesMap]);

	// The ex-VAT price per unit that applies to an article, which is what every line total is worked out
	// from: the applicant's choice (or the default) for a room with several prices, null while nothing
	// is chosen
	const exTaxPriceOf = useCallback((article: IArticle): string | null => {
		const options = article.price_options ?? [];
		if (options.length < 2) {
			return article.ex_tax_price;
		}
		const priceId = selectedArticlesMap.get(article.id)?.price_id ?? article.default_price_id;
		return options.find(o => o.price_id === priceId)?.ex_tax_price ?? null;
	}, [selectedArticlesMap]);

	// Fetch articles for selected resources (only in articles mode)
	const {data: articles, isLoading, error} = useResourceArticles({
		resourceIds: mode === 'articles' && resourceIds ? resourceIds.filter(id => id > 0) : []
	});

	// Group articles or process orders data
	const displayData = useMemo(() => {
		if (mode === 'orders') {
			// For orders mode, convert order lines to display format
			const orderItems: any[] = [];
			orders.forEach(order => {
				order.lines.forEach(line => {
					orderItems.push({
						id: `${order.order_id}-${line.article_mapping_id}`,
						name: line.name,
						price: `${line.unit_price}`,
						unit_price: line.unit_price.toString(),
						quantity: line.quantity,
						amount: line.amount,
						tax: line.tax,
						currency: line.currency,
						unit: line.unit
					});
				});
			});
			return { 'Orders': orderItems };
		}

		// Articles mode logic (existing)
		if (!articles) return {};

		// Create a map for tracking unique articles by article_id
		const uniqueArticleIds = new Set<string>();

		// First, create a map of all articles by ID for parent lookups
		const articlesById = new Map<number, IArticle>();
		articles.forEach(article => {
			articlesById.set(article.id, article);
		});

		const groupedArticles: Record<string, IArticle[]> = {};

		// Process parent/main articles first to ensure proper grouping
		const mainArticles = articles.filter(a => !a.parent_mapping_id);
		const childArticles = articles.filter(a => a.parent_mapping_id);

		// Filter out mandatory free articles. A room with several prices is never
		// "free": its price depends on the applicant's choice
		const filterMandatoryFreeArticle = (article: IArticle) => {
			const isMandatory = article.mandatory === 1 || article.mandatory === '1';
			const isFree = parseFloat(article.price) === 0 && (article.price_options?.length ?? 0) < 2;
			return !(isMandatory && isFree);
		};

		// Group main articles
		mainArticles.filter(filterMandatoryFreeArticle).forEach(article => {
			const groupName = article.article_group_name || 'Other';

			if (!groupedArticles[groupName]) {
				groupedArticles[groupName] = [];
			}

			// Only add if it's not already included
			if (!uniqueArticleIds.has(article.article_id)) {
				uniqueArticleIds.add(article.article_id);
				groupedArticles[groupName].push(article);
			}
		});

		// Group child articles, but deduplicate by article_id
		childArticles.filter(filterMandatoryFreeArticle).forEach(article => {
			// Skip if we've already seen this article_id
			if (uniqueArticleIds.has(article.article_id)) {
				return;
			}

			// Mark as seen
			uniqueArticleIds.add(article.article_id);

			// Find parent to determine group
			let parentArticle: IArticle | undefined;
			let currentId = article.parent_mapping_id;

			while (currentId) {
				parentArticle = articlesById.get(currentId);
				if (!parentArticle?.parent_mapping_id) {
					break;
				}
				currentId = parentArticle.parent_mapping_id;
			}

			const groupName = parentArticle?.article_group_name || article.article_group_name || 'Other';

			if (!groupedArticles[groupName]) {
				groupedArticles[groupName] = [];
			}

			groupedArticles[groupName].push(article);
		});

		// Remove empty groups
		return Object.fromEntries(
			Object.entries(groupedArticles).filter(([_, articles]) => articles.length > 0)
		);
	}, [articles, orders, mode]);

	// Function to update mandatory duration-priced (hourly and minute) articles
	const updateMandatoryDurationArticles = () => {
		if (!articles || !articles.length || !onArticlesChange) return;

		// Always create a fresh copy of articles to ensure updates
		const updatedArticles = [...selectedArticles];
		let hasUpdates = false;

		// First, add the mandatory articles with resource_id that aren't selected yet
		articles.forEach(article => {
			const quantity = mandatoryQuantity(article);
			if (quantity === null || selectedArticlesMap.has(article.id)) {
				return;
			}
			updatedArticles.push({
				id: article.id,
				quantity,
				parent_id: article.parent_mapping_id || null,
				// The preselected price; none while the applicant must choose
				price_id: article.default_price_id ?? null
			});
			hasUpdates = true;
		});

		// Then update quantities for already selected articles if needed
		for (let i = 0; i < updatedArticles.length; i++) {
			const articleOrder = updatedArticles[i];
			const article = articles.find(a => a.id === articleOrder.id);
			const quantity = article ? mandatoryQuantity(article) : null;

			if (quantity !== null && articleOrder.quantity !== quantity) {
				// Update the quantity to match duration
				updatedArticles[i] = {
					...articleOrder,
					quantity
				};
				hasUpdates = true;
			}
		}

		// Only trigger update if we have changes
		if (hasUpdates) {
			onArticlesChange(updatedArticles);
		}
	};

	// Use immediate effect when articles or duration changes
	useEffect(() => {
		// This must run synchronously on mount and updates
		if ((articles?.length || 0) > 0) {
			// Use setTimeout to ensure this runs after React's rendering cycle
			setTimeout(() => {
				updateMandatoryDurationArticles();
			}, 0);
		}
	}, [articles, durationHours, durationMinutes, durationDays, selectedArticles.length]);

	// Also run when selected articles map changes (for deeper dependency checking)
	useEffect(() => {
		if ((articles?.length || 0) > 0) {
			updateMandatoryDurationArticles();
		}
	}, [selectedArticlesMap]);

	const handleQuantityChange = (article: IArticle, quantity: number) => {
		// Only prevent editing for mandatory duration-priced articles with resource_id
		const isMandatoryDuration = mandatoryQuantity(article) !== null;

		if (isMandatoryDuration || !onArticlesChange) {
			return; // Can't change mandatory duration-priced articles
		}

		const newArticles = [...selectedArticles];
		const index = newArticles.findIndex(a => a.id === article.id);

		if (quantity <= 0) {
			// If quantity is 0 or negative, remove the article
			if (index !== -1) {
				newArticles.splice(index, 1);
			}
		} else {
			// Update or add article with new quantity
			if (index !== -1) {
				newArticles[index] = {...newArticles[index], quantity};
			} else {
				newArticles.push({
					id: article.id,
					quantity: quantity,
					parent_id: article.parent_mapping_id || null
				});
			}
		}

		onArticlesChange(newArticles);
	};

	// Calculate total price for all selected articles; pending while a room's price is not chosen.
	// Every line is worked out to the øre exactly as the server stores it, and the total is the sum of
	// those lines in øre, so it equals the stored order.
	const {totalOre, pricePending} = useMemo(() => {
		let pending = false;
		const total = selectedArticles.reduce((total, articleOrder) => {
			const article = articles?.find(a => a.id === articleOrder.id);
			if (article) {
				const quantity = mandatoryQuantity(article) ?? articleOrder.quantity;
				const exTaxPrice = exTaxPriceOf(article);
				if (exTaxPrice === null) {
					pending = true;
					return total;
				}
				return total + lineTotalOre({ex_tax_price: exTaxPrice, tax_percent: article.tax_percent}, quantity);
			}
			return total;
		}, 0);
		return {totalOre: total, pricePending: pending};
	}, [selectedArticles, articles, durationHours, durationMinutes, durationDays, exTaxPriceOf]);

	// Helper functions for incrementing and decrementing quantity
	const incrementQuantity = (article: IArticle) => {
		const isMandatoryDuration = mandatoryQuantity(article) !== null;

		if (isMandatoryDuration || !onArticlesChange) {
			return; // Can't change mandatory duration-priced articles
		}

		const newArticles = [...selectedArticles];
		const index = newArticles.findIndex(a => a.id === article.id);
		const currentQuantity = index !== -1 ? newArticles[index].quantity : 0;

		// Increment the quantity
		if (index !== -1) {
			newArticles[index] = {...newArticles[index], quantity: currentQuantity + 1};
		} else {
			newArticles.push({
				id: article.id,
				quantity: 1,
				parent_id: article.parent_mapping_id || null
			});
		}

		onArticlesChange(newArticles);
	};

	const decrementQuantity = (article: IArticle) => {
		const isMandatoryDuration = mandatoryQuantity(article) !== null;

		if (isMandatoryDuration || !onArticlesChange) {
			return; // Can't change mandatory duration-priced articles
		}

		const newArticles = [...selectedArticles];
		const index = newArticles.findIndex(a => a.id === article.id);

		if (index === -1) {
			return; // Article not in selection, nothing to decrement
		}

		const currentQuantity = newArticles[index].quantity;

		if (currentQuantity <= 1) {
			// Remove the article if quantity would be 0
			newArticles.splice(index, 1);
		} else {
			// Decrease the quantity
			newArticles[index] = {...newArticles[index], quantity: currentQuantity - 1};
		}

		onArticlesChange(newArticles);
	};

	if (mode === 'articles' && isLoading) {
		return <Spinner data-size="md" aria-label={t('common.loading')}/>;
	}

	if (mode === 'articles' && error) {
		return <div className={styles.error}>{t('common.error_loading_data')}</div>;
	}

	if (mode === 'articles' && (!articles || articles.length === 0)) {
		return <div className={styles.noArticles}>{t('bookingfrontend.no_articles_available')}</div>;
	}

	if (mode === 'orders' && (!orders || orders.length === 0)) {
		return <div className={styles.noArticles}>{t('bookingfrontend.no information available')}</div>;
	}

	return (
		<div className={styles.articleTableContainer}>
			{Object.entries(displayData).map(([groupName, groupItems]) => (
				<div key={groupName} className={styles.resourceArticleGroup}>
					<Table>
						<Table.Head>
							<Table.Row>
								<Table.HeaderCell>{t('bookingfrontend.article')}</Table.HeaderCell>
								<Table.HeaderCell>{t('bookingfrontend.price')}</Table.HeaderCell>
								{!readOnly && mode === 'articles' && <Table.HeaderCell>{t('booking.quantity')}</Table.HeaderCell>}
								{mode === 'orders' && <Table.HeaderCell>{t('booking.quantity')}</Table.HeaderCell>}
								<Table.HeaderCell>{t('booking.sum')}</Table.HeaderCell>
							</Table.Row>
						</Table.Head>
						<Table.Body>
							{groupItems.map((item: any) => {
								if (mode === 'orders') {
									// A minute-priced line shows the hourly price and the total incl. VAT, never the
									// per-minute price. Every other line shows its unit price and its sum incl. VAT too,
									// so the rows add up to the total below (the server's order sum is amount + tax).
									if (isMinuteUnit(item.unit)) {
										const lineTotal = Number(item.amount) + Number(item.tax || 0);
										const hourlyRate = hourlyRateOfOrderLine(item);
										return (
											<Table.Row key={item.id}>
												<Table.Cell>{item.name}</Table.Cell>
												<Table.Cell>{hourlyRate ? formatHourlyRate(hourlyRate, t) : '-'}</Table.Cell>
												<Table.Cell>{formatMinutes(Number(item.quantity), t)}</Table.Cell>
												<Table.Cell>{formatTotalWithHourlyRate(lineTotal, hourlyRate, item.currency, t)}</Table.Cell>
											</Table.Row>
										);
									}

									// «-» when the unit price incl. VAT cannot be read back from the line (no amount)
									const unitPriceIncl = unitPriceInclOfOrderLine(item);
									return (
										<Table.Row key={item.id}>
											<Table.Cell>{item.name}</Table.Cell>
											<Table.Cell>{unitPriceIncl === null ? '-' : formatCurrency(unitPriceIncl, item.currency)}</Table.Cell>
											<Table.Cell>{item.quantity}</Table.Cell>
											<Table.Cell>{formatCurrency(orderLineTotal(item), item.currency)}</Table.Cell>
										</Table.Row>
									);
								}

								// Articles mode rendering (existing logic)
								const article = item as IArticle;
								const selectedArticle = selectedArticlesMap.get(article.id);
								const quantity = selectedArticle?.quantity || 0;
								const isSelected = quantity > 0;

								const isMandatory = article.mandatory === 1 || article.mandatory === '1';
								const mandatoryDuration = mandatoryQuantity(article);
								const isMandatoryDuration = mandatoryDuration !== null;
								const isMinute = isMinuteUnit(article.unit);

								// For mandatory duration-priced articles, calculate total based on duration
								const calculatedQuantity = mandatoryDuration ?? quantity;
								const unitPrice = unitPriceOf(article);
								let priceText: string | null;
								let totalText: string | null;
								if (isMinute) {
									// A minute article never shows its per-minute price: the hourly price, and the
									// total explained by it. Null while the applicant has not chosen a price.
									const exTaxPrice = exTaxPriceOf(article);
									const priceInput = exTaxPrice === null
										? null
										: {ex_tax_price: exTaxPrice, tax_percent: article.tax_percent};
									const hourlyRate = priceInput === null ? null : hourlyRateOfMinuteArticle(priceInput);
									priceText = hourlyRate === null ? null : formatHourlyRate(hourlyRate, t);
									totalText = priceInput === null
										? null
										: formatTotalWithHourlyRate(minuteArticleTotal(priceInput, calculatedQuantity), hourlyRate, 'NOK', t);
								} else {
									// The price per unit incl. tax, as the chosen option has it. The sum is worked
									// out from the ex-VAT price to the øre, as the server stores it, so it can differ
									// from price x quantity by an øre; the sum is what is billed.
									const exTaxPrice = exTaxPriceOf(article);
									priceText = unitPrice === null ? null : formatCurrency(unitPrice);
									totalText = exTaxPrice === null
										? null
										: formatCurrency(lineTotalOre({ex_tax_price: exTaxPrice, tax_percent: article.tax_percent}, calculatedQuantity) / 100);
								}

								return (
									<Table.Row key={article.id}>
										<Table.Cell id={`${article.id}-name`}>
											{article.name.replace('- ', '')}
											{article.article_remark && (
												<div className={styles.articleRemark}
													 dangerouslySetInnerHTML={{__html: article.article_remark}}></div>
											)}
										</Table.Cell>
										<Table.Cell>
											{priceText === null ? t('bookingfrontend.price_pending') : priceText}
										</Table.Cell>
										{!readOnly && (
											<Table.Cell>
												<div className={styles.quantityControls}>
													{isMandatoryDuration ? (
														<>
															<span className={styles.hiddenButton}>
																<Button
																	variant="tertiary"
																	data-size="sm"
																	icon={true}
																	disabled={true}
																	aria-label={t('common.decrease')}
																>
																	<MinusCircleIcon aria-hidden="true"/>
																</Button>
															</span>
															<span className={styles.quantityValue}>
																{isMinute ? formatMinutes(durationMinutes, t) : formatQuantityWithUnit(mandatoryDuration, article.unit, t)}
															</span>
															<span className={styles.hiddenButton}>
																<Button
																	variant="tertiary"
																	data-size="sm"
																	icon={true}
																	disabled={true}
																	aria-label={t('common.increase')}
																>
																	<PlusCircleIcon aria-hidden="true"/>
																</Button>
															</span>
														</>
													) : (
														<>
															<Button
																variant="tertiary"
																data-size="sm"
																icon={true}
																onClick={() => decrementQuantity(article)}
																disabled={isMandatory || !isSelected}
																aria-label={t('common.decrease')}
															>
																<MinusCircleIcon aria-hidden="true"/>
															</Button>
															<span className={styles.quantityValue}>
																{isSelected ? (isMinute ? formatMinutes(quantity, t) : formatQuantity(quantity)) : 0}
															</span>
															<Button
																variant="tertiary"
																data-size="sm"
																icon={true}
																onClick={() => incrementQuantity(article)}
																disabled={isMandatory}
																aria-label={t('common.increase')}
															>
																<PlusCircleIcon aria-hidden="true"/>
															</Button>
														</>
													)}
												</div>
											</Table.Cell>
										)}
										<Table.Cell>
											{!isSelected ? '-' : totalText === null ? t('bookingfrontend.price_pending') : totalText}
										</Table.Cell>
									</Table.Row>
								);
							})}
						</Table.Body>
					</Table>
				</div>
			))}

			{mode === 'articles' && (pricePending || totalOre > 0) && (
				<div className={styles.totalSection} aria-live="polite">
					<strong>{t('bookingfrontend.total')}:</strong>
					<span>{pricePending ? t('bookingfrontend.price_pending') : formatCurrency(totalOre / 100)}</span>
				</div>
			)}
			
			{mode === 'orders' && orders.length > 0 && (
				<div className={styles.totalSection}>
					<strong>{t('bookingfrontend.total')}:</strong>
					<span>{formatCurrency(orders.reduce((sum, order) => sum + Number(order.sum), 0), orders[0]?.lines[0]?.currency || 'NOK')}</span>
				</div>
			)}
		</div>
	);
};

export default ArticleTable;