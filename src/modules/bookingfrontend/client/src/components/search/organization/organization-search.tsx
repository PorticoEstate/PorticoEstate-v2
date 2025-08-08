'use client'
import React, {FC, useMemo, useState, useEffect} from 'react';
import {useOrganizations} from "@/service/hooks/api-hooks";
import {Textfield, Button, Chip, Spinner} from '@digdir/designsystemet-react';
import styles from './organization-search.module.scss';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {ISearchOrganization} from '@/service/types/api/search.types';
import OrganizationResultItem from "./organization-result-item";

interface OrganizationSearchProps {
	initialOrganizations?: ISearchOrganization[];
}

// Interface for localStorage search state
interface StoredSearchState {
	textSearchQuery: string;
	where: string;
	timestamp: number;
}

const STORAGE_KEY = 'organization_search_state';
const STORAGE_TTL = 24 * 60 * 60 * 1000; // 24 hours in milliseconds

const OrganizationSearch: FC<OrganizationSearchProps> = ({ initialOrganizations }) => {
	// Initialize state for search filters
	const [textSearchQuery, setTextSearchQuery] = useState<string>('');
	const [where, setWhere] = useState<string>('');

	// Fetch organizations using the dedicated endpoint
	const {data: organizations, isLoading, error} = useOrganizations({
		initialData: initialOrganizations
	});
	const t = useTrans();

	// Load saved search state from localStorage on initial render
	useEffect(() => {
		// Only run in browser environment
		if (typeof window !== 'undefined') {
			try {
				const savedState = localStorage.getItem(STORAGE_KEY);
				if (savedState) {
					const parsedState: StoredSearchState = JSON.parse(savedState);

					// Check if state is still valid (not expired)
					const now = Date.now();
					if (now - parsedState.timestamp < STORAGE_TTL) {
						setTextSearchQuery(parsedState.textSearchQuery);
						setWhere(parsedState.where);
					} else {
						// Remove expired state
						localStorage.removeItem(STORAGE_KEY);
					}
				}
			} catch (e) {
				console.error('Error loading search state from localStorage:', e);
				localStorage.removeItem(STORAGE_KEY);
			}
		}
	}, []);

	// Extract unique districts from organizations
	const districts = useMemo(() => {
		if (!organizations) return [];

		const uniqueDistricts = Array.from(
			new Set(organizations.map(org => org.district).filter(Boolean))
		);

		return uniqueDistricts.sort();
	}, [organizations]);

	// Calculate similarity score for sorting
	const calculateSimilarity = (
		organization: ISearchOrganization,
		query: string
	): number => {
		const organizationName = organization.name.toLowerCase();
		const queryLower = query.toLowerCase();

		// Exact match gets highest score
		if (organizationName === queryLower) {
			return 100;
		}

		// Starts with query gets high score
		if (organizationName.startsWith(queryLower)) {
			// Calculate how much of the string is matched
			const matchRatio = queryLower.length / organizationName.length;
			const score = 75 + (matchRatio * 20); // This gives higher scores to closer matches
			return score;
		}

		// Contains query gets medium score
		if (organizationName.includes(queryLower)) {
			return 50;
		}

		// Partial word match gets lower score
		const words = queryLower.split(' ');
		for (const word of words) {
			if (word.length > 2 && organizationName.includes(word)) {
				return 25;
			}
		}

		return 0;
	};

	// Apply all filters to organizations and sort by relevance
	const filteredOrganizations = useMemo(() => {
		if (!organizations?.length) return [];

		// If no filters are applied, return empty array (don't show results by default)
		if (!textSearchQuery.trim() && !where) {
			return [];
		}

		let filtered = organizations

		// Only apply text search if something has been entered
		if (textSearchQuery && textSearchQuery.trim() !== '') {
			const query = textSearchQuery.toLowerCase();
			filtered = filtered.filter(organization => {
				const organizationNameMatch = organization.name.toLowerCase().includes(query);

				return organizationNameMatch;
			});

			// Sort by relevance/similarity
			filtered.sort((a, b) => {
				const scoreA = calculateSimilarity(a, textSearchQuery);
				const scoreB = calculateSimilarity(b, textSearchQuery);
				return scoreB - scoreA;
			});
		}

		// District filter
		// if (where) {
		// 	filtered = filtered.filter(organization => organization.district === where);
		// }

		return filtered;
	}, [organizations, textSearchQuery, where]);

	// Clear all filters
	const clearFilters = () => {
		setTextSearchQuery('');
		setWhere('');

		// Clear localStorage when filters are reset
		if (typeof window !== 'undefined') {
			localStorage.removeItem(STORAGE_KEY);
		}
	};

	// Save search state to localStorage whenever it changes
	useEffect(() => {
		// Only save if there's actually something to save
		if (textSearchQuery || where !== '') {
			if (typeof window !== 'undefined') {
				try {
					const stateToSave: StoredSearchState = {
						textSearchQuery,
						where,
						timestamp: Date.now()
					};
					localStorage.setItem(STORAGE_KEY, JSON.stringify(stateToSave));
				} catch (e) {
					console.error('Error saving search state to localStorage:', e);
				}
			}
		}
	}, [textSearchQuery, where]);

	if (isLoading) {
		return (
			<div className={styles.loadingContainer}>
				<Spinner data-size="lg" aria-label={t('common.loading...')}/>
				<p>{t('common.loading...')}</p>
			</div>
		);
	}

	if (error) {
		return (
			<div className={styles.errorContainer}>
				<p>{t('common.error_occurred')}</p>
				<Button onClick={() => window.location.reload()}>{t('common.try_again')}</Button>
			</div>
		);
	}

	return (
		<div className={styles.organizationSearchContainer}>
			<section id="organization-filter" className={styles.filterSection}>
				<div className={styles.searchInputs}>
					<div className={styles.searchField}>
						<Textfield
							label={t('common.search')}
							value={textSearchQuery}
							onChange={(e) => setTextSearchQuery(e.target.value)}
							placeholder={t('bookingfrontend.search_organizations')}
						/>
					</div>

					{/*<div className={styles.districtFilter}>*/}
					{/*  <Field>*/}
					{/*    <Label>{t('bookingfrontend.where')}</Label>*/}
					{/*    <Select*/}
					{/*      value={where}*/}
					{/*      onChange={(e) => setWhere(e.target.value)}*/}
					{/*    >*/}
					{/*      <Select.Option value="">{t('booking.all')}</Select.Option>*/}
					{/*      {districts.map(district => (*/}
					{/*        <Select.Option key={district} value={district}>*/}
					{/*          {district}*/}
					{/*        </Select.Option>*/}
					{/*      ))}*/}
					{/*    </Select>*/}
					{/*  </Field>*/}
					{/*</div>*/}
				</div>

				{(textSearchQuery || where !== '') && (
					<div className={styles.activeFilters}>
						<span>{t('common.filter')}:</span>
						<div className={styles.filterChips}>
							{textSearchQuery && (
								<Chip.Removable data-color="brand1" onClick={() => setTextSearchQuery('')}>
									{t('common.search')}: {textSearchQuery}
								</Chip.Removable>
							)}
							{where && (
								<Chip.Removable data-color="brand1" onClick={() => setWhere('')}>
									{t('bookingfrontend.town part')}: {where}
								</Chip.Removable>
							)}
							<Button
								variant="tertiary"
								data-size="sm"
								onClick={clearFilters}
							>
								{t('bookingfrontend.search_clear_filters')}
							</Button>
						</div>
					</div>
				)}
			</section>

			<section id="organization-results" className={styles.resultsSection}>
				{!textSearchQuery.trim() && !where ? (
					<div className={styles.noResults}>
						<p>{t('bookingfrontend.search_use_filters_to_search')}</p>
					</div>
				) : filteredOrganizations.length > 0 ? (
					<div className={styles.organizationGrid}>
						{filteredOrganizations.map(organization => (
							<OrganizationResultItem key={organization.id} organization={organization}/>
						))}
					</div>
				) : (
					<div className={styles.noResults}>
						<p>{t('bookingfrontend.search_no_organizations_match')}</p>
						<Button
							variant="secondary"
							onClick={clearFilters}
						>
							{t('bookingfrontend.search_clear_filters')}
						</Button>
					</div>
				)}
			</section>
		</div>
	);
}

export default OrganizationSearch;