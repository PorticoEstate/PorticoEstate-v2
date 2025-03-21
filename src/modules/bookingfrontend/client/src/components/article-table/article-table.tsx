// components/article-table/article-table.tsx
import React, {useEffect, useMemo} from 'react';
import {Checkbox, Table, Textfield, Spinner, Button} from '@digdir/designsystemet-react';
import {useResourceArticles} from '@/service/hooks/api-hooks';
import {ArticleOrder, IArticle} from '@/service/types/api/order-articles.types';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {DateTime} from 'luxon';
import {MinusCircleIcon, PlusCircleIcon} from '@navikt/aksel-icons';
import styles from './article-table.module.scss';

interface ArticleTableProps {
	resourceIds: number[];
	selectedArticles: ArticleOrder[];
	onArticlesChange?: (articles: ArticleOrder[]) => void;
	readOnly?: boolean;
	startTime?: Date;
	endTime?: Date;
}

const ArticleTable: React.FC<ArticleTableProps> = ({
													   resourceIds,
													   selectedArticles,
													   onArticlesChange,
													   readOnly = false,
													   startTime,
													   endTime
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

	// Create a map for easier lookup of selected articles
	const selectedArticlesMap = useMemo(() => {
		return new Map(selectedArticles?.map(article => [article.id, article]));
	}, [selectedArticles]);

	// Fetch articles for selected resources
	const {data: articles, isLoading, error} = useResourceArticles({
		resourceIds: resourceIds.filter(id => id > 0)
	});

	// Group articles while deduplicating based on article_id
	const articlesByGroup = useMemo(() => {
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

		// Filter out mandatory free articles
		const filterMandatoryFreeArticle = (article: IArticle) => {
			const isMandatory = article.mandatory === 1 || article.mandatory === '1';
			const isFree = parseFloat(article.price) === 0;
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
	}, [articles]);

	// Function to update mandatory hourly articles
	const updateMandatoryHourlyArticles = () => {
		if (!articles || !articles.length || !onArticlesChange) return;

		// Always create a fresh copy of articles to ensure updates
		const updatedArticles = [...selectedArticles];
		let hasUpdates = false;

		// First, handle mandatory hourly articles with resource_id that aren't selected yet
		const mandatoryHourlyArticlesToAdd = articles.filter(article =>
			article.unit === 'hour' &&
			article.resource_id &&
			(article.mandatory === 1 || article.mandatory === '1') &&
			!selectedArticlesMap.has(article.id)
		);

		// Add any missing mandatory hourly articles
		if (mandatoryHourlyArticlesToAdd.length > 0) {
			mandatoryHourlyArticlesToAdd.forEach(article => {
				updatedArticles.push({
					id: article.id,
					quantity: durationHours,
					parent_id: article.parent_mapping_id || null
				});
			});
			hasUpdates = true;
		}

		// Then update quantities for already selected articles if needed
		for (let i = 0; i < updatedArticles.length; i++) {
			const articleOrder = updatedArticles[i];
			const article = articles.find(a => a.id === articleOrder.id);

			if (article &&
				article.unit === 'hour' &&
				article.resource_id &&
				(article.mandatory === 1 || article.mandatory === '1') &&
				articleOrder.quantity !== durationHours) {

				// Update the quantity to match duration
				updatedArticles[i] = {
					...articleOrder,
					quantity: durationHours
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
				updateMandatoryHourlyArticles();
			}, 0);
		}
	}, [articles, durationHours, selectedArticles.length]);

	// Also run when selected articles map changes (for deeper dependency checking)
	useEffect(() => {
		if ((articles?.length || 0) > 0) {
			updateMandatoryHourlyArticles();
		}
	}, [selectedArticlesMap]);

	const handleQuantityChange = (article: IArticle, quantity: number) => {
		// Only prevent editing for mandatory hourly articles with resource_id
		const isMandatoryHourly = article.unit === 'hour' &&
			article.resource_id &&
			(article.mandatory === 1 || article.mandatory === '1');

		if (isMandatoryHourly || !onArticlesChange) {
			return; // Can't change mandatory hourly articles
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

	// Calculate total price for all selected articles
	const totalPrice = useMemo(() => {
		return selectedArticles.reduce((total, articleOrder) => {
			const article = articles?.find(a => a.id === articleOrder.id);
			if (article) {
				const isMandatoryHourly = article.unit === 'hour' &&
					article.resource_id &&
					(article.mandatory === 1 || article.mandatory === '1');

				const quantity = isMandatoryHourly ? durationHours : articleOrder.quantity;
				return total + (parseFloat(article.unit_price) * quantity);
			}
			return total;
		}, 0).toFixed(2);
	}, [selectedArticles, articles, durationHours]);

	// Helper functions for incrementing and decrementing quantity
	const incrementQuantity = (article: IArticle) => {
		const isMandatoryHourly = article.unit === 'hour' &&
			article.resource_id &&
			(article.mandatory === 1 || article.mandatory === '1');

		if (isMandatoryHourly || !onArticlesChange) {
			return; // Can't change mandatory hourly articles
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
		const isMandatoryHourly = article.unit === 'hour' &&
			article.resource_id &&
			(article.mandatory === 1 || article.mandatory === '1');

		if (isMandatoryHourly || !onArticlesChange) {
			return; // Can't change mandatory hourly articles
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

	if (isLoading) {
		return <Spinner data-size="md" aria-label={t('common.loading')}/>;
	}

	if (error) {
		return <div className={styles.error}>{t('common.error_loading_data')}</div>;
	}

	if (!articles || articles.length === 0) {
		return <div className={styles.noArticles}>{t('bookingfrontend.no_articles_available')}</div>;
	}

	return (
		<div className={styles.articleTableContainer}>
			{Object.entries(articlesByGroup).map(([groupName, groupArticles]) => (
				<div key={groupName} className={styles.resourceArticleGroup}>
					{/*<h4 className={styles.resourceTitle}>{groupName}</h4>*/}
					<Table>
						<Table.Head>
							<Table.Row>
								<Table.HeaderCell>{t('bookingfrontend.article')}</Table.HeaderCell>
								<Table.HeaderCell>{t('bookingfrontend.price')}</Table.HeaderCell>
								{!readOnly && <Table.HeaderCell>{t('booking.quantity')}</Table.HeaderCell>}
								<Table.HeaderCell>{t('booking.sum')}</Table.HeaderCell>
							</Table.Row>
						</Table.Head>
						<Table.Body>
							{groupArticles.map(article => {
								const selectedArticle = selectedArticlesMap.get(article.id);
								const quantity = selectedArticle?.quantity || 0;
								const isSelected = quantity > 0;

								const isMandatory = article.mandatory === 1 || article.mandatory === '1';
								const isMandatoryHourly = isMandatory &&
									article.unit === 'hour' &&
									article.resource_id;

								// For mandatory hourly articles, calculate total based on duration
								const calculatedQuantity = isMandatoryHourly ? durationHours : quantity;
								const total = (parseFloat(article.unit_price) * calculatedQuantity).toFixed(2);

								return (
									<Table.Row key={article.id}>
										<Table.Cell id={`${article.id}-name`}>
											{article.name.replace('- ', '')}
											{article.article_remark && (
												<div className={styles.articleRemark}
													 dangerouslySetInnerHTML={{__html: article.article_remark}}></div>
											)}
											{/*{isMandatoryHourly && (*/}
											{/*	<div className={styles.hourlyLabel}>*/}
											{/*		({t('bookingfrontend.calculated_by_duration')}: {durationHours} {t('common.hours')})*/}
											{/*	</div>*/}
											{/*)}*/}
										</Table.Cell>
										<Table.Cell>{article.price} kr</Table.Cell>
										{!readOnly && (
											<Table.Cell>
												<div className={styles.quantityControls}>
													{isMandatoryHourly ? (
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
																{durationHours}
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
																{isSelected ? quantity : 0}
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
										<Table.Cell>{isSelected ? `${total} kr` : '-'}</Table.Cell>
									</Table.Row>
								);
							})}
						</Table.Body>
					</Table>
				</div>
			))}

			{parseFloat(totalPrice) > 0 && (
				<div className={styles.totalSection}>
					<strong>{t('bookingfrontend.total')}:</strong>
					<span>{totalPrice} kr</span>
				</div>
			)}
		</div>
	);
};

export default ArticleTable;