import React, {Fragment, useMemo, useState, FC, useCallback, useEffect, useRef} from 'react';
import {IResource} from '@/service/types/resource.types';
import {
	Button,
	Checkbox,
	Chip, Details,
	Field,
	Select, Spinner,
	Switch,
	Tag,
	Textfield,
	ValidationMessage
} from '@digdir/designsystemet-react';
import {DateTime} from 'luxon';
import MobileDialog from '@/components/dialog/mobile-dialog';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import {useBuilding, useBuildingResources} from '@/service/api/building';
import {FCallTempEvent} from '@/components/building-calendar/building-calendar.types';
import ColourCircle from '@/components/building-calendar/modules/colour-circle/colour-circle';
import styles from './application-crud.module.scss';
import {useForm, Controller} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {
	useBookingUser,
	useBuildingAgeGroups,
	useBuildingAudience, useBuildingSeasons,
	useCreatePartialApplication, useDeleteApplicationDocument, useDeletePartialApplication,
	usePartialApplications,
	useUpdatePartialApplication, useUploadApplicationDocument, useBuildingSchedule,
	useServerSettings
} from "@/service/hooks/api-hooks";
import {NewPartialApplication, IUpdatePartialApplication, IApplication, RecurringInfo, RecurringInfoUtils} from "@/service/types/api/application.types";
import {applicationTimeToLux} from "@/components/layout/header/shopping-cart/shopping-cart-content";
import {IAgeGroup, IAudience, IBuilding, Season} from "@/service/types/Building";
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import {ApplicationFormData, applicationFormSchema} from './application-form';
import {IBookingUser, IDelegate} from "@/service/types/api.types";
import ArticleTable from "@/components/article-table/article-table";
import {ArticleOrder} from "@/service/types/api/order-articles.types";
import {isDevMode, phpGWLink} from "@/service/util";
import {IEvent} from "@/service/pecalendar.types";
import {isApplicationDeactivated} from "@/service/utils/deactivation-utils";
import {ResourceUsesTimeSlots} from "@/components/building-calendar/util/calender-helpers";
import {Trans} from "react-i18next";
import ApplicationLoginLink from "@/components/building-calendar/modules/event/edit/application-login-link";

interface ApplicationCrudProps {
	selectedTempApplication?: Partial<FCallTempEvent>;
	building_id: string | number;
	applicationId?: number;
	date_id?: number;
	onClose: () => void;
	showDebug?: boolean;
}

interface ApplicationCrudInnerProps extends ApplicationCrudProps {
	building?: IBuilding;
	buildingResources?: IResource[];
	partials?: { list: IApplication[], total_sum: number };
	audience?: IAudience[];
	agegroups?: IAgeGroup[];
	existingApplication?: IApplication;
	onSubmitSuccess?: (data: ApplicationFormData) => void;
	lastSubmittedData?: Partial<ApplicationFormData> | null;
	bookingUser?: IBookingUser;
	seasons?: Season[];
	events?: IEvent[];
}

const ApplicationCrudWrapper: FC<ApplicationCrudProps> = (props) => {
	const [lastSubmittedData, setLastSubmittedData] = useState<Partial<ApplicationFormData> | null>(null);
	const [restoredProps, setRestoredProps] = useState<ApplicationCrudProps | null>(null);

	// Only fetch if we have a building_id
	const building_id = props.building_id || props.selectedTempApplication?.extendedProps?.building_id;

	const {data: building, isLoading: buildingLoading} = useBuilding(
		building_id ? +building_id : undefined
	);

	const {data: seasons, isLoading: seasonsLoading} = useBuildingSeasons(building_id ? +building_id : undefined);
	const {data: buildingResources, isLoading: buildingResourcesLoading} = useBuildingResources(
		building_id
	);
	const {data: partials, isLoading: partialsLoading} = usePartialApplications();
	const {data: audience, isLoading: audienceLoading} = useBuildingAudience(
		building_id ? +building_id : undefined
	);
	const {data: agegroups, isLoading: agegroupsLoading} = useBuildingAgeGroups(
		building_id ? +building_id : undefined
	);
	const {data: bookingUser, isLoading: userLoading} = useBookingUser();
	const {data: events, isLoading: eventsLoading} = useBuildingSchedule({
		building_id: building_id ? +building_id : undefined,
		weeks: [DateTime.now()]
	});

	// Check for pending recurring application data after login and update props if needed
	useEffect(() => {
		if (bookingUser) {
			const pendingData = localStorage.getItem('pendingRecurringApplication');
			if (pendingData) {
				try {
					const storedData = JSON.parse(pendingData);

					// Check if data is expired (10 minutes = 600000 ms)
					const isExpired = storedData.timestamp && (Date.now() - storedData.timestamp > 600000);

					if (isExpired) {
						localStorage.removeItem('pendingRecurringApplication');
						return;
					}

					// Use the same applicationId resolution logic as existingApplication
					const currentApplicationId = props.applicationId || props.selectedTempApplication?.extendedProps?.applicationId;

					// More lenient context matching - if we have stored selectedTempApplication and building matches,
					// then we can restore the props even if applicationId doesn't match (catch-22 situation)
					const buildingMatches = storedData.building_id === props.building_id;
					const dateMatches = storedData.date_id === props.date_id;
					const applicationMatches = storedData.applicationId === currentApplicationId;
					const hasStoredApplication = storedData.selectedTempApplication && storedData.applicationId;

					// Match context if building and date match, and we have stored application data
					// Don't require applicationId match since that creates a catch-22
					if (buildingMatches && dateMatches && hasStoredApplication) {
						// Always restore the selectedTempApplication from stored data
						// This ensures the existingApplication calculation can find the correct application
						setRestoredProps({
							...props,
							selectedTempApplication: storedData.selectedTempApplication,
							applicationId: storedData.applicationId
						});

						// Clear the stored data
						localStorage.removeItem('pendingRecurringApplication');
					}
				} catch (error) {
					console.error('Error parsing pending recurring application data:', error);
					localStorage.removeItem('pendingRecurringApplication');
				}
			}
		}
	}, [bookingUser, props]);

	const existingApplication = useMemo(() => {
		// Use restored props if available, otherwise use original props
		const effectiveProps = restoredProps || props;
		const applicationId = effectiveProps.applicationId || effectiveProps.selectedTempApplication?.extendedProps?.applicationId;

		if (applicationId === undefined) {
			return null;
		}
		if (!partials) {
			return undefined;
		}

		return partials.list.find(a => a.id === applicationId) || null;
	}, [props.selectedTempApplication, partials, props.applicationId, restoredProps]);

	// Don't show loading state if we don't have a building_id
	if (!building_id) {
		return null;
	}

	if (seasonsLoading || userLoading || buildingLoading || buildingResourcesLoading || partialsLoading || agegroupsLoading || audienceLoading || eventsLoading || existingApplication === undefined) {
		return null;
	}

	const effectiveProps = restoredProps || props;
	const isOpen = effectiveProps.selectedTempApplication !== undefined || effectiveProps.applicationId !== undefined;

	if (!isOpen) {
		return null;
	}

	return (
		<div style={{display: isOpen ? 'block' : 'none'}}>
			<ApplicationCrud
				agegroups={agegroups}
				building={building}
				existingApplication={existingApplication || undefined}
				audience={audience}
				buildingResources={buildingResources}
				partials={partials}
				lastSubmittedData={lastSubmittedData}
				bookingUser={bookingUser}
				seasons={seasons}
				events={events}
				showDebug={isDevMode()} // Always enable debug in dev mode
				onSubmitSuccess={(data) => {
					setLastSubmittedData(data);
					// Clear restored props after successful submission
					if (restoredProps) {
						setRestoredProps(null);
					}
				}}

				{...effectiveProps}
				onClose={() => {
					// Clear restored props when dialog is closed
					if (restoredProps) {
						setRestoredProps(null);
					}
					effectiveProps.onClose();
				}}
			/>
		</div>
	);
}

const ApplicationCrud: React.FC<ApplicationCrudInnerProps> = (props) => {
	const [filesToUpload, setFilesToUpload] = useState<FileList | null>(null);
	const [isUploadingFiles, setIsUploadingFiles] = useState(false);
	const [hasExternalChanges, setHasExternalChanges] = useState(false);
	const {building, buildingResources, audience, agegroups, partials, existingApplication, events} = props;
	const t = useTrans();
	const [isEditingResources, setIsEditingResources] = useState(false);
	const createMutation = useCreatePartialApplication();
	const deleteMutation = useDeletePartialApplication();
	const updateMutation = useUpdatePartialApplication();
	const uploadDocumentMutation = useUploadApplicationDocument();
	const deleteDocumentMutation = useDeleteApplicationDocument();
	const participantsSectionRef = useRef<HTMLDivElement>(null);
	const {data: serverSettings} = useServerSettings();



	const isWithinBusinessHours = useCallback((date: Date, resourceIds: string[] = []): boolean => {
		if (!props.seasons) {
			return true;
		}
		const dt = DateTime.fromJSDate(date);
		const dayOfWeek = dt.weekday;
		const timeStr = dt.toFormat('HH:mm:ss');

		// Get active seasons for the given date that match selected resources
		const activeSeasons = props.seasons?.filter(season => {
			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);

			// Check if season has any resources that match selected resources
			const hasMatchingResources = resourceIds.length === 0 ||
				season.resources.some(seasonResource =>
					resourceIds.includes(seasonResource.id.toString())
				);

			return season.active && dt >= seasonStart && dt <= seasonEnd && hasMatchingResources;
		});

		// If no active seasons for this date, consider it within hours (will be validated elsewhere)
		if (activeSeasons.length === 0) {
			return true;
		}

		// Get all boundaries for this day from active seasons
		const dayBoundaries = activeSeasons.flatMap(season =>
			season.boundaries.filter(b => b.wday === dayOfWeek)
		);

		// If no boundaries defined for this day, consider it within hours (will be validated elsewhere)
		if (dayBoundaries.length === 0) {
			return true;
		}

		// Special handling for late night hours (23:45:00 or later)
		// Find the latest boundary for this day
		const sortedBoundaries = [...dayBoundaries].sort((a, b) =>
			b.to_.localeCompare(a.to_)
		);

		const latestBoundaryTo = sortedBoundaries[0]?.to_;

		// If the latest boundary extends to 23:45:00 or later, allow bookings until midnight
		if (latestBoundaryTo && latestBoundaryTo >= '23:45:00' && timeStr <= '24:00:00') {
			return true;
		}

		// Standard check: time falls within any boundary of any active season
		return dayBoundaries.some(boundary =>
			boundary.from_ <= timeStr && boundary.to_ >= timeStr
		);
	}, [props.seasons]);

	const defaultStartEnd = useMemo(() => {
		// Helper function to create default timestamps
		const createDefaultTimes = () => {
			const startTime = new Date();
			// Set to next full hour
			startTime.setHours(startTime.getHours() + 1, 0, 0, 0);

			const endTime = new Date(startTime);
			// Set end time 1 hour after start time
			endTime.setHours(endTime.getHours() + 1);

			// Check if this time range is within business hours (use empty resources for initial calculation)
			if (!isWithinBusinessHours(startTime, []) || !isWithinBusinessHours(endTime, [])) {
				// Move to middle of next day
				const tomorrow = DateTime.fromJSDate(startTime).plus({days: 1});
				// Find active season for tomorrow (use empty resources for initial calculation)
				const tomorrowActiveSeasons = props.seasons!.filter(season => {
					const seasonStart = DateTime.fromISO(season.from_);
					const seasonEnd = DateTime.fromISO(season.to_);
					return season.active && tomorrow >= seasonStart && tomorrow <= seasonEnd;
				});

				if (tomorrowActiveSeasons.length > 0) {
					// Get boundaries for tomorrow
					const tomorrowBoundaries = tomorrowActiveSeasons[0].boundaries
						.filter(b => b.wday === tomorrow.weekday);

					if (tomorrowBoundaries.length > 0) {
						// Get middle of first boundary
						const boundary = tomorrowBoundaries[0];
						const startHour = parseInt(boundary.from_.split(':')[0]);
						const endHour = parseInt(boundary.to_.split(':')[0]);
						const middleHour = Math.floor((startHour + endHour) / 2);

						return {
							start: tomorrow.set({hour: middleHour, minute: 0}).toJSDate(),
							end: tomorrow.set({hour: middleHour + 1, minute: 0}).toJSDate()
						};
					}
				}
			}

			return {start: startTime, end: endTime};
		};

		if (!existingApplication?.dates || (props.selectedTempApplication && !props.selectedTempApplication?.id) || (props.selectedTempApplication === undefined && props.date_id === undefined)) {
			const defaultTimes = createDefaultTimes();
			return {
				start: props.selectedTempApplication?.start || defaultTimes.start,
				end: props.selectedTempApplication?.end || defaultTimes.end
			};
		}

		const dateId = props.selectedTempApplication?.id || props.date_id;
		const dateEntry = dateId !== undefined ? existingApplication.dates.find(d => +d.id === +dateId) : undefined;

		if (!dateEntry) {
			const defaultTimes = createDefaultTimes();
			return {
				start: props.selectedTempApplication?.start || defaultTimes.start,
				end: props.selectedTempApplication?.end || defaultTimes.end
			};
		}

		return {
			start: applicationTimeToLux(dateEntry.from_).toJSDate(),
			end: applicationTimeToLux(dateEntry.to_).toJSDate()
		};
	}, [existingApplication, props.selectedTempApplication, props.date_id, isWithinBusinessHours, props.seasons]);

	// In defaultValues section, add articles field and recurring fields:
	const defaultValues = useMemo(() => {
		if (existingApplication) {
			// Convert orders to ArticleOrder format if they exist
			const articleOrders: ArticleOrder[] = [];

			// Process orders from existing application
			if (existingApplication.orders && existingApplication.orders.length > 0) {
				existingApplication.orders.forEach(order => {
					if (order.lines && order.lines.length > 0) {
						order.lines.forEach(line => {
							articleOrders.push({
								id: line.article_mapping_id,
								quantity: +line.quantity,
								parent_id: line.parent_mapping_id > 0 ? line.parent_mapping_id : null
							});
						});
					}
				});
			}
			return {
				title: existingApplication.name,
				start: defaultStartEnd.start,
				end: defaultStartEnd.end,
				homepage: existingApplication.homepage || '',
				description: existingApplication.description || '',
				equipment: existingApplication.equipment || '',
				organizer: existingApplication.organizer || '',
				resources: existingApplication.resources?.filter(res =>
						!ResourceUsesTimeSlots(res) &&
						(props.building ? !isApplicationDeactivated(res, props.building) : !res.deactivate_application)
					).map((res) => res.id.toString()) ||
					props.selectedTempApplication?.extendedProps?.resources?.filter(resId => {
						const resource = buildingResources?.find(r => r.id === +resId);
						return resource &&
							!ResourceUsesTimeSlots(resource) &&
							(props.building ? !isApplicationDeactivated(resource, props.building) : !resource?.deactivate_application);
					}).map(String) ||
					[],
				audience: existingApplication.audience || undefined,
				articles: articleOrders, // Use converted orders
				agegroups: agegroups?.map(ag => ({
					id: ag.id,
					male: existingApplication.agegroups?.find(eag => eag.id === ag.id)?.male || 0,
					female: 0,
					name: ag.name,
					description: ag.description,
					sort: ag.sort,
				})) || [],
				// Parse existing recurring_info if available
				isRecurring: !!RecurringInfoUtils.parse(existingApplication.recurring_info),
				recurring_info: RecurringInfoUtils.parse(existingApplication.recurring_info) || undefined,
				// Set organization data if existing application has it
				organization_id: existingApplication.customer_organization_id || undefined,
				organization_number: existingApplication.customer_organization_number || undefined,
				organization_name: existingApplication.customer_organization_name || undefined
			};
		}

		// Check if we have a baseApplication to prefill from
		const baseApplication = props.selectedTempApplication?.extendedProps?.baseApplication;
		if (baseApplication) {
			// Convert orders to ArticleOrder format if they exist
			const articleOrders: ArticleOrder[] = [];

			// Process orders from base application
			if (baseApplication.orders && baseApplication.orders.length > 0) {
				baseApplication.orders.forEach(order => {
					if (order.lines && order.lines.length > 0) {
						order.lines.forEach(line => {
							articleOrders.push({
								id: line.article_mapping_id,
								quantity: +line.quantity,
								parent_id: line.parent_mapping_id > 0 ? line.parent_mapping_id : null
							});
						});
					}
				});
			}

			return {
				title: baseApplication.name || '',
				organizer: baseApplication.organizer || props.bookingUser?.name || '',
				start: defaultStartEnd.start, // Keep dates empty as requested
				end: defaultStartEnd.end,     // Keep dates empty as requested
				homepage: baseApplication.homepage || '',
				description: baseApplication.description || '',
				equipment: baseApplication.equipment || '',
				resources: props.selectedTempApplication?.extendedProps?.resources?.map(String) ?? [],
				audience: baseApplication.audience ?? undefined,
				articles: articleOrders,
				agegroups: agegroups?.map(ag => ({
					id: ag.id,
					male: baseApplication.agegroups?.find(eag => eag.id === ag.id)?.male || 0,
					female: 0,
					name: ag.name,
					description: ag.description,
					sort: ag.sort,
				})) || [],
				// Parse existing recurring_info if available from baseApplication
				isRecurring: !!RecurringInfoUtils.parse(baseApplication.recurring_info),
				recurring_info: RecurringInfoUtils.parse(baseApplication.recurring_info) || undefined,
				// Set organization data from baseApplication if available
				organization_id: baseApplication.customer_organization_id || undefined,
				organization_number: baseApplication.customer_organization_number || undefined,
				organization_name: baseApplication.customer_organization_name || undefined
			};
		}

		// Use lastSubmittedData if available, otherwise use default empty values
		return {
			title: props.lastSubmittedData?.title ?? '',
			organizer: props.bookingUser?.name ?? '',
			start: defaultStartEnd.start,
			end: defaultStartEnd.end,
			homepage: props.lastSubmittedData?.homepage ?? '',
			description: props.lastSubmittedData?.description ?? '',
			equipment: props.lastSubmittedData?.equipment ?? '',
			resources: props.selectedTempApplication?.extendedProps?.resources?.filter(resId => {
				const resource = buildingResources?.find(r => r.id === +resId);
				return resource &&
					!ResourceUsesTimeSlots(resource) &&
					(props.building ? !isApplicationDeactivated(resource, props.building) : !resource?.deactivate_application);
			}).map(String) ?? [],
			audience: props.lastSubmittedData?.audience ?? undefined,
			articles: props.lastSubmittedData?.articles ?? [],
			agegroups: agegroups?.map(ag => ({
				id: ag.id,
				male: props.lastSubmittedData?.agegroups?.find(eag => eag.id === ag.id)?.male ?? 0,
				female: 0,
				name: ag.name,
				description: ag.description,
				sort: ag.sort,
			})) || [],
			// Default values for recurring booking
			isRecurring: false,
			recurring_info: undefined,
			// Default organization values
			organization_id: undefined,
			organization_number: undefined,
			organization_name: undefined
		};
	}, [existingApplication, props.lastSubmittedData, defaultStartEnd, agegroups, props.selectedTempApplication, props.bookingUser]);

	const {
		control,
		handleSubmit,
		watch,
		setValue,
		getValues,
		setError,
		clearErrors,
		formState: {errors, isDirty, dirtyFields, isSubmitted}
	} = useForm<ApplicationFormData>({
		resolver: zodResolver(applicationFormSchema),
		defaultValues: defaultValues
	});

	const startTime = watch('start');
	const endTime = watch('end');
	const isRecurring = watch('isRecurring');
	const outseason = watch('recurring_info.outseason');
	// Function to find the current season based on start/end time
	const getCurrentSeason = useCallback(() => {
		if (!props.seasons || !startTime) return null;

		const referenceDate = DateTime.fromJSDate(startTime);
		return props.seasons.find(season => {
			if (!season.active) return false;
			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);
			return referenceDate >= seasonStart.startOf('day') && referenceDate <= seasonEnd.endOf('day');
		});
	}, [props.seasons, startTime]);

	// Calculate max date for repeat until (end of current season)
	const maxRepeatUntilDate = useMemo(() => {
		const currentSeason = getCurrentSeason();
		return currentSeason ? DateTime.fromISO(currentSeason.to_).toJSDate() : null;
	}, [getCurrentSeason]);

	// Function to calculate min/max times for a specific date
	const getTimeBoundariesForDate = useCallback((
		date: Date | null,
		resourceIds: string[] = []
	): [string, string] => {
		let minTime = '24:00:00';
		let maxTime = '00:00:00';

		if (!props.seasons) {
			return ['00:00:00', '24:00:00'];
		}

		const now = DateTime.now();
		const referenceDate = date ? DateTime.fromJSDate(date) : now;

		// Filter applicable seasons
		const applicableSeasons = props.seasons.filter(season => {
			if (!season.active) return false;

			const seasonStart = DateTime.fromISO(season.from_);
			const seasonEnd = DateTime.fromISO(season.to_);

			// Check if season covers this date (or "now" if no date given)
			const isInSeason = date
				? referenceDate >= seasonStart.startOf('day') && referenceDate <= seasonEnd.endOf('day')
				: now >= seasonStart && now <= seasonEnd;

			if (!isInSeason) return false;

			// Check matching resources (if provided)
			const hasMatchingResources =
				resourceIds.length === 0 ||
				season.resources.some(res => resourceIds.includes(res.id.toString()));

			return hasMatchingResources;
		});

		// Collect relevant boundaries
		applicableSeasons.forEach(season => {
			let seasonBoundaries = season.boundaries;

			if (date) {
				const dayOfWeek = referenceDate.weekday;
				seasonBoundaries = seasonBoundaries.filter(b => b.wday === dayOfWeek);
			}

			seasonBoundaries.forEach(boundary => {
				if (boundary.from_ < minTime) minTime = boundary.from_;
				if (boundary.to_ > maxTime) maxTime = boundary.to_;
			});
		});

		// Fallback defaults
		const effectiveMinTime = minTime === '24:00:00' ? '06:00:00' : minTime;
		const effectiveMaxTime =
			maxTime === '00:00:00' || maxTime >= '23:45:00' ? '24:00:00' : maxTime;

		// Extra fallback for day-specific queries with no matches
		if (date && minTime === '24:00:00' && maxTime === '00:00:00') {
			return ['06:00:00', '24:00:00'];
		}

		return [effectiveMinTime, effectiveMaxTime];
	}, [props.seasons]);



	// Scroll to participant counts error if it exists after form submission
	useEffect(() => {
		if (isSubmitted && errors.agegroups?.['root']?.message && participantsSectionRef.current) {
			participantsSectionRef.current.scrollIntoView({behavior: 'smooth', block: 'start'});
		}
	}, [isSubmitted, errors.agegroups]);

	// Check for pending recurring application data after login and restore form data
	useEffect(() => {
		if (props.bookingUser) {
			const pendingData = localStorage.getItem('pendingRecurringApplication');
			if (pendingData) {
				try {
					const storedData = JSON.parse(pendingData);

					// Check if data is expired (10 minutes = 600000 ms)
					const isExpired = storedData.timestamp && (Date.now() - storedData.timestamp > 600000);

					if (isExpired) {
						return; // Don't remove here since wrapper handles it
					}

					// Use the same applicationId resolution logic as existingApplication
					const currentApplicationId = props.applicationId || props.selectedTempApplication?.extendedProps?.applicationId;

					if (storedData.building_id === props.building_id &&
						storedData.applicationId === currentApplicationId &&
						storedData.date_id === props.date_id) {

						// For existing applications, don't mark fields as dirty to prevent duplicate creation
						const shouldMarkDirty = !existingApplication;

						// Restore form data with proper date handling
						Object.keys(storedData).forEach(key => {
							if (key !== 'building_id' && key !== 'selectedTempApplication' &&
								key !== 'applicationId' && key !== 'date_id' && key !== 'timestamp') {

								// Handle Date objects that were serialized as ISO strings
								if ((key === 'start' || key === 'end') && typeof storedData[key] === 'string') {
									setValue(key as any, new Date(storedData[key]), { shouldDirty: shouldMarkDirty });
								} else {
									setValue(key as any, storedData[key], { shouldDirty: shouldMarkDirty });
								}
							}
						});

						// Initialize recurring data
						if (storedData.isRecurring) {
							const startDate = storedData.start ? new Date(storedData.start) : new Date();
							const oneWeekLater = new Date(startDate);
							oneWeekLater.setDate(oneWeekLater.getDate() + 7);

							setValue('recurring_info.repeat_until', oneWeekLater.toISOString().split('T')[0], { shouldDirty: shouldMarkDirty });
							setValue('recurring_info.field_interval', 1, { shouldDirty: shouldMarkDirty });
							setValue('recurring_info.outseason', false, { shouldDirty: shouldMarkDirty });

							// Set default organization if not already set from stored data
							if (!storedData.organization_id && props.bookingUser?.delegates?.filter(delegate => delegate.active).length && props.bookingUser.delegates.filter(delegate => delegate.active).length > 0) {
								const firstOrg = props.bookingUser.delegates.filter(delegate => delegate.active)[0];
								setValue('organization_id', firstOrg.org_id, { shouldDirty: shouldMarkDirty, shouldValidate: true });
								setValue('organization_number', firstOrg.organization_number, { shouldDirty: shouldMarkDirty });
								setValue('organization_name', firstOrg.name, { shouldDirty: shouldMarkDirty });
							}
						}
					}
				} catch (error) {
					console.error('Error parsing pending recurring application data:', error);
				}
			}
		}
	}, [props.bookingUser, props.building_id, props.applicationId, props.date_id, setValue, existingApplication]);

	const selectedResources = watch('resources');

	// Calculate time boundaries for start time
	const [startMinTime, startMaxTime] = useMemo(() => {
		return getTimeBoundariesForDate(startTime, selectedResources || []);
	}, [getTimeBoundariesForDate, startTime, selectedResources]);

	// Calculate time boundaries for end time
	const [endMinTime, endMaxTime] = useMemo(() => {
		return getTimeBoundariesForDate(endTime, selectedResources || []);
	}, [getTimeBoundariesForDate, endTime, selectedResources]);

	// Add useEffect to check times when they change
	useEffect(() => {
		if (!startTime) return;

		// Check if date is in the past
		const now = new Date();
		if (startTime < now) {
			setError('start', {
				type: 'manual',
				message: t('bookingfrontend.start_time_in_past')
			});
		}
		// Check if within business hours
		else if (!isWithinBusinessHours(startTime, selectedResources)) {
			setError('start', {
				type: 'manual',
				message: t('bookingfrontend.start_time_outside_business_hours')
			});
		} else {
			clearErrors('start');
		}

		// Check if start time is later than end time
		const endTimeValue = getValues('end');
		if (endTimeValue && startTime > endTimeValue) {
			// Create a new end time 30 minutes after start time
			const newEndTime = new Date(startTime);
			newEndTime.setMinutes(newEndTime.getMinutes() + 30);

			// Ensure the new end time doesn't exceed the maximum allowed time
			const newEndTimeStr = DateTime.fromJSDate(newEndTime).toFormat('HH:mm:ss');

			// If the new end time would exceed the maximum time, use the maximum time instead
			if (newEndTimeStr > startMaxTime) {
				// Use the same day as the start time but with max hours/minutes
				const [maxHours, maxMinutes] = startMaxTime.split(':').map(Number);
				newEndTime.setHours(maxHours);
				newEndTime.setMinutes(maxMinutes);
				newEndTime.setSeconds(0);
			}

			setValue('end', newEndTime, {shouldDirty: true});
		}
	}, [startTime, isWithinBusinessHours, setError, clearErrors, t, setValue, getValues, startMaxTime]);

	useEffect(() => {
		if (!endTime) return;

		// Check if date is in the past
		const now = new Date();
		if (endTime < now) {
			setError('end', {
				type: 'manual',
				message: t('bookingfrontend.end_time_in_past')
			});
		}
		// Check if within business hours
		else if (!isWithinBusinessHours(endTime, selectedResources)) {
			setError('end', {
				type: 'manual',
				message: t('bookingfrontend.end_time_outside_business_hours')
			});
		} else {
			clearErrors('end');
		}
	}, [endTime, isWithinBusinessHours, setError, clearErrors, t]);
	const formatDateForInput = (date: Date) => {
		return DateTime.fromJSDate(date).toFormat('yyyy-MM-dd\'T\'HH:mm');
	};

	const checkEventOverlap = useCallback((startDate: Date, endDate: Date, resourceIds: string[]): boolean => {
		// Get current events from context or props
		if (!events) return false; // No events to check against

		const selectStart = DateTime.fromJSDate(startDate);
		const selectEnd = DateTime.fromJSDate(endDate);

		// Prevent selections in the past
		const now = DateTime.now();
		if (selectStart < now) {
			return false;
		}

		// Filter to only get actual events (not background events) for selected resources
		const selectedResourceIds = resourceIds.map(Number);

		// Check for resources with deny_application_if_booked flag
		const resourcesWithDenyFlag = buildingResources
			?.filter(res => selectedResourceIds.includes(res.id) && res.deny_application_if_booked === 1);

		const hasResourceWithDenyFlag = resourcesWithDenyFlag && resourcesWithDenyFlag.length > 0;

		// If no resources have deny flag, allow the booking
		if (!hasResourceWithDenyFlag) return true;

		// Get all events for the selected resources
		const relevantEvents = events
			.filter(event => {
				// Check if event has any of the selected resources
				const eventHasSelectedResource = event.resources.some(res =>
					selectedResourceIds.includes(res.id)
				);

				// Skip if editing existing event
				if (existingApplication && existingApplication.id === event.id) {
					return false;
				}

				return eventHasSelectedResource;
			});

		// Check for overlap with each event's times
		const hasOverlap = relevantEvents.some(event => {
			const eventStart = DateTime.fromISO(event.from_);
			const eventEnd = DateTime.fromISO(event.to_);

			// Check if the selection overlaps with this event
			return !(selectEnd <= eventStart || selectStart >= eventEnd);
		});

		// If no overlap, return true (booking is allowed)
		return !hasOverlap;
	}, [events, buildingResources, existingApplication]);

	const onSubmit = async (data: ApplicationFormData) => {
		if (!building || !buildingResources) {
			return;
		}

		// Check for dates in the past
		const now = new Date();
		const startInPast = data.start < now;
		const endInPast = data.end < now;

		// Check for times outside business hours
		const startOutsideHours = !isWithinBusinessHours(data.start, data.resources);
		const endOutsideHours = !isWithinBusinessHours(data.end, data.resources);

		// Check if any selected resource denies applications if already booked
		const selectedResources = buildingResources.filter(res => data.resources.some(id => +id === res.id));
		const hasResourceWithDenyFlag = selectedResources.some(res => res.deny_application_if_booked === 1);

		// Validate dates
		if (startInPast || endInPast || startOutsideHours || endOutsideHours) {

			if (startInPast) {
				setError('start', {
					type: 'manual',
					message: t('bookingfrontend.start_time_in_past')
				});
			} else if (startOutsideHours) {
				setError('start', {
					type: 'manual',
					message: t('bookingfrontend.start_time_outside_business_hours')
				});
			}

			if (endInPast) {
				setError('end', {
					type: 'manual',
					message: t('bookingfrontend.end_time_in_past')
				});
			} else if (endOutsideHours) {
				setError('end', {
					type: 'manual',
					message: t('bookingfrontend.end_time_outside_business_hours')
				});
			}
			return;
		}

		// If any resource has deny_application_if_booked=1, check for overlaps
		if (hasResourceWithDenyFlag) {
			// Use our checkEventOverlap function to check for overlaps
			const noOverlap = checkEventOverlap(data.start, data.end, data.resources);

			if (!noOverlap) {
				setError('resources', {
					type: 'manual',
					message: t('bookingfrontend.resource_overlap_detected')
				});
				return;
			}
		}

		if (existingApplication) {
			const updatedApplication: IUpdatePartialApplication = {
				id: existingApplication.id,
				building_id: +props.building_id,
			}
			if (dirtyFields.start || dirtyFields.end) {
				updatedApplication.dates = existingApplication.dates?.map(date => {
					const dateId = props.selectedTempApplication?.id || props.date_id;
					if (date.id && dateId && +dateId === +date.id) {
						return {
							...date,
							from_: data.start.toISOString(),
							to_: data.end.toISOString()
						}
					}
					return date
				})
			}
			if (dirtyFields.resources) {
				updatedApplication.resources = buildingResources.filter(res => data.resources.some(selected => (+selected === res.id)))
			}

			if (dirtyFields.articles) {
				updatedApplication.articles = data.articles;
			}

			if (dirtyFields.agegroups) {
				updatedApplication.agegroups = data.agegroups.map(ag => ({
					...ag,
					female: 0 // Since we're only tracking male numbers
				}));
			}
			if (dirtyFields.title) {
				updatedApplication.name = data.title;
			}
			const checkFields: (keyof typeof dirtyFields)[] = [
				'audience',
				'homepage',
				'description',
				'equipment',
				'organizer'
			]
			for (const checkField of checkFields) {
				if (dirtyFields[checkField]) {
					(updatedApplication as any)[checkField] = data[checkField];
				}
			}

			// Handle recurring_info
			if (dirtyFields.isRecurring || dirtyFields.recurring_info) {
				if (data.isRecurring && data.recurring_info) {
					updatedApplication.recurring_info = data.recurring_info;
				} else {
					// Explicitly set to null to clear recurring info in backend
					updatedApplication.recurring_info = null;
				}
			}

			// Handle organization data for recurring bookings
			if (dirtyFields.isRecurring || dirtyFields.organization_id || dirtyFields.organization_number || dirtyFields.organization_name) {
				if (data.isRecurring && data.organization_id) {
					(updatedApplication as any).customer_identifier_type = 'organization_number';
					(updatedApplication as any).customer_organization_id = data.organization_id;
					(updatedApplication as any).customer_organization_number = data.organization_number;
					(updatedApplication as any).customer_organization_name = data.organization_name;
				} else {
					// Explicitly set to null to clear organization data in backend
					(updatedApplication as any).customer_identifier_type = null;
					(updatedApplication as any).customer_organization_id = null;
					(updatedApplication as any).customer_organization_number = null;
					(updatedApplication as any).customer_organization_name = null;
				}
			}


			const result = await updateMutation.mutateAsync({
				id: existingApplication.id,
				application: updatedApplication
			});

			props.onSubmitSuccess?.(data);
			props.onClose();
			return;
		}

		const newApplication: NewPartialApplication = {
			building_name: building!.name,
			building_id: building!.id,
			dates: [
				{
					from_: data.start.toISOString(),
					to_: data.end.toISOString()
				}
			],
			audience: data.audience,
			agegroups: data.agegroups.map(ag => ({
				...ag,
				female: 0 // Since we're only tracking male numbers
			})),
			articles: data.articles,
			organizer: data.organizer || '',
			name: data.title,
			homepage: data.homepage,
			description: data.description,
			equipment: data.equipment,
			resources: data.resources.map(res => (+res)),
			activity_id: buildingResources!.find(a => a.id === +data.resources[0] && !!a.activity_id)?.activity_id || 1,
			// Add recurring info if enabled
			recurring_info: data.isRecurring && data.recurring_info ? data.recurring_info : undefined
		}

		// If recurring booking, set organization data and customer type
		if (data.isRecurring && data.organization_id) {
			(newApplication as any).customer_identifier_type = 'organization_number';
			(newApplication as any).customer_organization_id = data.organization_id;
			(newApplication as any).customer_organization_number = data.organization_number;
			(newApplication as any).customer_organization_name = data.organization_name;
		}

		const result = await createMutation.mutateAsync(newApplication);
		if (filesToUpload && filesToUpload.length > 0) {
			setIsUploadingFiles(true);
			const formData = new FormData();
			Array.from(filesToUpload).forEach(file => {
				formData.append('files[]', file);
			});

			await uploadDocumentMutation.mutateAsync({
				id: result.id,
				files: formData
			});
			setIsUploadingFiles(false);
		}
		props.onSubmitSuccess?.(data);
		props.onClose();
	};

	const handleDelete = () => {
		if (existingApplication) {
			// TODO: fix deleting
			deleteMutation.mutate(existingApplication.id);

			// setTempEvents(prev => {
			//     const newEvents = {...prev};
			//     delete newEvents[selectedTempEvent.id!];
			//     return newEvents;
			// });
		}
		props.onClose();
	};

	const toggleResource = (resourceId: string) => {
		// Check if resource is deactivated or uses timeslots
		const resource = buildingResources?.find(r => r.id === +resourceId);
		if (resource && (
			ResourceUsesTimeSlots(resource) ||
			(props.building ? isApplicationDeactivated(resource, props.building) : resource?.deactivate_application)
		)) {
			return; // Prevent selection of deactivated resources or timeslot resources
		}

		const currentResources = watch('resources');
		const resourceIndex = currentResources.indexOf(resourceId);

		if (resourceIndex === -1) {
			setValue('resources', [...currentResources, resourceId], {shouldDirty: true});
		} else {
			setValue(
				'resources',
				currentResources.filter(id => id !== resourceId),
				{shouldDirty: true}
			);
		}
	};

	const toggleAllResources = () => {
		if (!buildingResources) return;

		// Filter out deactivated resources and timeslot resources
		const activeResources = buildingResources.filter(r =>
			!ResourceUsesTimeSlots(r) &&
			(props.building ? !isApplicationDeactivated(r, props.building) : !r.deactivate_application)
		);
		const allActiveResourceIds = activeResources.map(r => String(r.id));

		if (selectedResources.length === activeResources.length) {
			setValue('resources', [], {shouldDirty: true});
		} else {
			setValue('resources', allActiveResourceIds, {shouldDirty: true});
		}
	};

	const renderResourceList = () => {
		if (!buildingResources) return null;

		if (!isEditingResources) {
			// Show only selected resources with edit button
			return (
				<div className={styles.selectedResourcesList}>
					<div className={styles.resourcesHeader}>
						<h4>{t('bookingfrontend.chosen rent object')}</h4>
						<Button
							variant="tertiary"
							data-size="sm"
							onClick={() => setIsEditingResources(true)}
						>
							{t('common.edit')}
						</Button>
					</div>
					<div style={{
						display: 'flex',
						gap: '0.5rem'
					}}>

						<div style={{
							display: 'flex',
							flexWrap: 'wrap',
							gap: '0.5rem'
						}}>
							{buildingResources
								.filter(resource => selectedResources.includes(String(resource.id)))
								.map(resource => (
									<Tag
										data-color={'neutral'} data-size={"md"} key={resource.id}
										className={styles.selectedResourceItem}>
										<ColourCircle resourceId={resource.id} size="medium"/>
										<span className={styles.resourceName}>{resource.name}</span>
									</Tag>
								))}

						</div>
						{/*<Button*/}
						{/*    variant="tertiary"*/}
						{/*    data-size="sm"*/}
						{/*    onClick={() => setIsEditingResources(true)}*/}
						{/*    icon={true}*/}
						{/*>*/}
						{/*    <FontAwesomeIcon icon={faPen}/>*/}
						{/*</Button>*/}
					</div>


				</div>
			);
		}

		// Show all resources with checkboxes when editing
		return (
			<div className={styles.resourceList}>
				<div className={styles.resourcesHeader}>
					<h4>{t('bookingfrontend.choose resources')} <span className="required-asterisk">*</span></h4>
					<Button
						variant="tertiary"
						data-size="sm"
						onClick={() => setIsEditingResources(false)}
					>
						{t('common.done')}
					</Button>
				</div>
				{/*<Checkbox*/}
				{/*    value="select-all"*/}
				{/*    id="resource-all"*/}
				{/*    label={`${t('common.select all')} ${t('bookingfrontend.resources').toLowerCase()}`}*/}
				{/*    checked={buildingResources && selectedResources.length === buildingResources.length}*/}
				{/*    onChange={toggleAllResources}*/}
				{/*    className={styles.resourceCheckbox}*/}
				{/*/>*/}
				<div style={{
					display: 'flex',
					gap: '0.5rem'
				}}>

					<div style={{
						display: 'flex',
						flexWrap: 'wrap',
						gap: '0.5rem'
					}}>
						{buildingResources
							.filter(resource => !ResourceUsesTimeSlots(resource))
							.map(resource => (
								// <div key={resource.id} className={styles.resourceItem}>
								<Chip.Checkbox
									value={String(resource.id)}
									id={`resource-${resource.id}`}
									key={resource.id}
									data-color={'brand1'}
									data-size={"md"}
									checked={selectedResources.includes(String(resource.id))}
									disabled={props.building ? isApplicationDeactivated(resource, props.building) : resource.deactivate_application}
									onChange={() => toggleResource(String(resource.id))}
									className={`${styles.resourceItem} ${props.building ? isApplicationDeactivated(resource, props.building) : resource.deactivate_application ? styles.deactivated : ''}`}
								>
									<ColourCircle resourceId={resource.id} size="medium"/>
									<span>{resource.name}</span>
									{(props.building ? isApplicationDeactivated(resource, props.building) : resource.deactivate_application) && (
										<span className={styles.deactivatedText}>
                                        ({t('bookingfrontend.booking_unavailable')})
                                    </span>
									)}
								</Chip.Checkbox>
								// </div>
							))}
					</div>
				</div>
			</div>
		);
	};


	return (
		<form onSubmit={handleSubmit(onSubmit)}>
			<MobileDialog
				dialogId={'application-dialog'}
				open={true}
				onClose={props.onClose}
				size={'hd'}
				title={
					<div className={styles.dialogHeader}>
						<h3>{existingApplication ? t('bookingfrontend.edit application') : t('bookingfrontend.new application')}</h3>
					</div>
				}
				footer={
					<div style={{display: 'flex', gap: '1rem'}}>
						{existingApplication && (
							<Button
								variant="tertiary"
								color="danger"
								onClick={handleDelete}
								type="button"
							>
								{t('common.delete')}
							</Button>
						)}
						<Button
							variant="primary"
							type={existingApplication && !isDirty && hasExternalChanges ? "button" : "submit"}
							disabled={!(isDirty || !existingApplication || hasExternalChanges)}
							onClick={existingApplication && !isDirty && hasExternalChanges ? props.onClose : undefined}
						>
							{existingApplication && !isDirty && hasExternalChanges ? t('common.save') : t('common.save')}
						</Button>
					</div>
				}
			>
				<section className={styles.eventForm}>
					<div className={`${styles.formGroup}`}>
						<Controller
							name="title"
							control={control}
							render={({field}) => (
								<Textfield
									label={t('bookingfrontend.title')}
									{...field}
									error={errors.title?.message ? t(errors.title.message) : undefined}
									placeholder={t('bookingfrontend.enter_title')}
									required
								/>
							)}
						/>
					</div>

					<div className={styles.dateTimeGroup}>
						<div className={styles.dateTimeInput}>
							<Controller
								name="start"
								control={control}
								render={({field: {value, onChange, ...field}}) => (
									<>
										<label>{t('common.start')}</label>
										{/*<input*/}
										{/*    type="datetime-local"*/}
										{/*    {...field}*/}
										{/*    value={formatDateForInput(value)}*/}
										{/*    onChange={e => onChange(new Date(e.target.value))}*/}
										{/*/>*/}
										<CalendarDatePicker
											currentDate={value}
											view={'timeGridDay'}
											showTimeSelect
											onDateChange={onChange}
											minTime={startMinTime}
											maxTime={startMaxTime}
											allowPastDates={existingApplication !== undefined}
											showDebug={props.showDebug || isDevMode()}
											seasons={props.seasons}
										/>


										{errors.start &&
											<span className={styles.error}>{errors.start.message}</span>}
									</>
								)}
							/>
						</div>
						<div className={styles.dateTimeInput}>
							<Controller
								name="end"
								control={control}
								render={({field: {value, onChange, ...field}}) => (
									<>
										<label>{t('common.end')}</label>
										{/*<input*/}
										{/*    type="datetime-local"*/}
										{/*    {...field}*/}
										{/*    value={formatDateForInput(value)}*/}
										{/*    onChange={e => onChange(new Date(e.target.value))}*/}
										{/*/>*/}
										<CalendarDatePicker
											currentDate={value}
											view={'timeGridDay'}
											showTimeSelect
											onDateChange={onChange}
											minTime={endMinTime}
											maxTime={endMaxTime}
											allowPastDates={existingApplication !== undefined}
											showDebug={props.showDebug || isDevMode()}
											seasons={props.seasons}
										/>
										{errors.end &&
											<span className={styles.error}>{errors.end.message}</span>}
									</>
								)}
							/>
						</div>
					</div>

					{/* Recurring Booking Section */}
					<div className={`${styles.formGroup} ${styles.wide}`}>
						{props.bookingUser ? (
							// Check if user has active delegate organizations
							(props.bookingUser.delegates?.filter(delegate => delegate.active).length || 0) > 0 ? (
								// Show checkbox for authenticated users with organizations
								<Controller
									name="isRecurring"
									control={control}
									render={({field}) => (
										<div>
											<Switch
												label={t('bookingfrontend.make_recurring')}
												checked={field.value || false}
												onChange={(e) => {
												const isChecked = e.target.checked;
												field.onChange(isChecked);

												if (isChecked) {
													// Initialize recurring data when switch is turned on
													const startDate = watch('start');
													const oneWeekLater = new Date(startDate);
													oneWeekLater.setDate(oneWeekLater.getDate() + 7);

													// Set default to one week later or end of season, whichever is earlier
													const currentSeason = getCurrentSeason();
													let defaultEndDate = oneWeekLater.toISOString().split('T')[0];

													if (currentSeason) {
														const seasonEnd = DateTime.fromISO(currentSeason.to_).toJSDate();
														if (oneWeekLater > seasonEnd) {
															defaultEndDate = seasonEnd.toISOString().split('T')[0];
														}
													}

													setValue('recurring_info.repeat_until', defaultEndDate);
													setValue('recurring_info.field_interval', 1);
													setValue('recurring_info.outseason', false);
													// Set default to first organization
													const firstOrg = props.bookingUser?.delegates?.filter(delegate => delegate.active)[0];
													if (firstOrg) {
														setValue('organization_id', firstOrg.org_id, { shouldDirty: true, shouldValidate: true });
														setValue('organization_number', firstOrg.organization_number, { shouldDirty: true });
														setValue('organization_name', firstOrg.name, { shouldDirty: true });
													}
												} else {
													// Clear recurring data when switch is turned off
													setValue('recurring_info.repeat_until', '');
													setValue('recurring_info.field_interval', 1);
													setValue('recurring_info.outseason', false);
													// Clear organization fields
													setValue('organization_id', undefined);
													setValue('organization_number', undefined);
													setValue('organization_name', undefined);
												}
											}}
										/>
									</div>
								)}
							/>
							) : (
								// Show message for authenticated users without organizations
								<div>
									<p style={{margin: 0, color: 'var(--ds-color-text-subtle)'}}>
										{t('bookingfrontend.no_organizations_for_recurring')}
									</p>
								</div>
							)
						) : (
							// Show link for non-authenticated users
							<div>
								<p style={{margin: 0}}>
									<Trans i18nKey="bookingfrontend.recurring_login_link_text" components={{linkTag: <ApplicationLoginLink onClick={() => {
											// Check if user has selected files that will be lost
											if (filesToUpload && filesToUpload.length > 0) {
												const fileNames = Array.from(filesToUpload).map(file => file.name).join(', ');
												const message = t('bookingfrontend.files_will_be_lost_on_login', {
													fileNames: fileNames,
													defaultValue: `You have selected files (${fileNames}) that will be lost when you log in. Do you want to continue?`
												});
												const shouldProceed = window.confirm(message);
												if (!shouldProceed) return;
											}

											// Store current form data in localStorage with timestamp
											const currentFormData = getValues();
											// Use the same applicationId resolution logic as existingApplication
											const resolvedApplicationId = props.applicationId || props.selectedTempApplication?.extendedProps?.applicationId;

											// Ensure dates are properly serialized
											const dataToStore = {
												...currentFormData,
												start: currentFormData.start?.toISOString(),
												end: currentFormData.end?.toISOString(),
												isRecurring: true,
												building_id: props.building_id,
												selectedTempApplication: props.selectedTempApplication,
												applicationId: resolvedApplicationId,
												date_id: props.date_id,
												timestamp: Date.now() // Add timestamp for expiration
											};
											localStorage.setItem('pendingRecurringApplication', JSON.stringify(dataToStore));

											// Redirect to login with after parameter
											const afterPath = window.location.href.split('bookingfrontend')[1];
											window.location.href = phpGWLink(['bookingfrontend', 'login/'], {after: encodeURI(afterPath)});
										}}/>
									}} />

								</p>
							</div>
						)}

						{/* Show recurring fields only when switch is checked */}
						{watch('isRecurring') && (
							<div style={{borderLeft: '3px solid var(--ds-color-border-brand1)'}}>
								{/* Organization selector for recurring bookings */}
								<div className={`${styles.formGroup}`} style={{gridColumn: 1, marginBottom: '1rem'}}>
									<Controller
										name="organization_id"
										control={control}
										render={({field}) => (
											<>
												<label>{t('bookingfrontend.organization')}</label>
												<Select
													{...field}
													value={field.value?.toString() || ''}
													onChange={(e) => {
														const selectedOrgId = parseInt(e.target.value);
														field.onChange(selectedOrgId || undefined);

														// Also set organization number and name
														const selectedDelegate = props.bookingUser?.delegates?.find(d => d.org_id === selectedOrgId);
														if (selectedDelegate) {
															setValue('organization_number', selectedDelegate.organization_number);
															setValue('organization_name', selectedDelegate.name);
														}
													}}
												>
													{props.bookingUser?.delegates?.filter(delegate => delegate.active).map((delegate) => (
														<option key={delegate.org_id} value={delegate.org_id}>
															{delegate.name} ({delegate.organization_number})
														</option>
													))}
												</Select>
												{errors.organization_id &&
													<span className={styles.error}>{errors.organization_id.message}</span>}
											</>
										)}
									/>
								</div>

								{/* Repeat interval selection */}
								<div className={`${styles.formGroup}`} style={{gridColumn: 1, marginBottom: '1rem'}}>
									<Controller
										name="recurring_info.field_interval"
										control={control}
										render={({field}) => (
											<Field>
												<label>{t('bookingfrontend.repeat_every_weeks')}</label>

												<Select
													{...field}
													value={field.value || 1}
													onChange={(e) => field.onChange(parseInt(e.target.value) || 1)}
													aria-invalid={!!errors.recurring_info?.field_interval}
												>
													<Select.Option
														value={1}>{t('bookingfrontend.every_week')}</Select.Option>
													<Select.Option
														value={2}>{t('bookingfrontend.every_2_weeks')}</Select.Option>
													<Select.Option
														value={3}>{t('bookingfrontend.every_3_weeks')}</Select.Option>
													<Select.Option
														value={4}>{t('bookingfrontend.every_4_weeks')}</Select.Option>
												</Select>
												{errors.recurring_info?.field_interval && (
													<ValidationMessage>
														{errors.recurring_info.field_interval.message}
													</ValidationMessage>
												)}
											</Field>
										)}
									/>
								</div>

								{/* Repeat until options */}
								<div className={`${styles.formGroup}`} style={{gridColumn: 1, marginBottom: '1rem'}}>



									{/* Repeat until date picker - only show when outseason is not checked */}
									{!outseason && (
										<div>
											<Controller
												name="recurring_info.repeat_until"
												control={control}
												render={({field: {value, onChange, ...field}}) => (
													<>
														<label>{t('bookingfrontend.repeat_until_date')}</label>
														<CalendarDatePicker
															currentDate={value ? new Date(value) : undefined}
															view={'dayGridDay'}
															showTimeSelect={false}
															onDateChange={(date) => {
																if (date && maxRepeatUntilDate && date > maxRepeatUntilDate) {
																	// Don't allow dates beyond current season end
																	onChange(maxRepeatUntilDate.toISOString().split('T')[0]);
																} else {
																	onChange(date ? date.toISOString().split('T')[0] : '');
																}
															}}
															maxDate={maxRepeatUntilDate || undefined}
															allowPastDates={false}
															allowEmpty={true}
															showDebug={props.showDebug}
															seasons={props.seasons}
														/>
														{errors.recurring_info?.repeat_until &&
															<span className={styles.error}>{errors.recurring_info.repeat_until.message}</span>}
													</>
												)}
											/>
										</div>
									)}
									{/* Out-season checkbox */}
									<Controller
										name="recurring_info.outseason"
										control={control}
										render={({field}) => (
											<Checkbox
												checked={field.value || false}
												onChange={(e) => {
													const isChecked = e.target.checked;
													field.onChange(isChecked);

													if (isChecked) {
														// Clear repeat until when outseason is selected
														setValue('recurring_info.repeat_until', '');
													} else {
														// Set default repeat until date when outseason is unchecked
														const startDate = watch('start');
														const oneWeekLater = new Date(startDate);
														oneWeekLater.setDate(oneWeekLater.getDate() + 7);

														const currentSeason = getCurrentSeason();
														let defaultEndDate = oneWeekLater.toISOString().split('T')[0];

														if (currentSeason) {
															const seasonEnd = DateTime.fromISO(currentSeason.to_).toJSDate();
															if (oneWeekLater > seasonEnd) {
																defaultEndDate = seasonEnd.toISOString().split('T')[0];
															}
														}

														setValue('recurring_info.repeat_until', defaultEndDate);
													}
												}}
												label={t('bookingfrontend.repeat_until_end_of_season')}
												description={getCurrentSeason() ? `${t('bookingfrontend.current_season_ends')}: ${DateTime.fromISO(getCurrentSeason()!.to_).toFormat('dd.MM.yyyy')}` : undefined}

											/>
										)}
									/>
								</div>
							</div>
						)}
					</div>

					<div className={`${styles.formGroup} ${styles.wide}`}>
						{renderResourceList()}
						{errors.resources?.message && (
							<span className={styles.error}>{t(errors.resources.message)}</span>
						)}
					</div>

					{/* Articles section - only show if activated in server settings */}
					{selectedResources.length > 0 && serverSettings?.booking_config?.activate_application_articles === true && (
						<div className={`${styles.formGroup} ${styles.wide}`}>
							<div className={styles.resourcesHeader}>
								<h4>{t('bookingfrontend.articles')}</h4>
							</div>
							<Controller
								name="articles"
								control={control}
								render={({field}) => (
									<ArticleTable
										resourceIds={selectedResources.map(id => parseInt(id))}
										selectedArticles={field.value || []}
										onArticlesChange={field.onChange}
										startTime={watch('start')}
										endTime={watch('end')}
									/>
								)}
							/>
						</div>
					)}
					<div className={`${styles.formGroup}`}>
						<div className={styles.resourcesHeader}>
							<h4>{t('bookingfrontend.target audience')} <span className="required-asterisk">*</span></h4>
						</div>
						<Controller
							name="audience"
							control={control}
							defaultValue={existingApplication?.audience || []}
							render={({field}) => (
								<Field>
									<Select
										required
										{...field}
										value={field.value?.[0]}
										onChange={(event) => field.onChange([(Number(event.target.value))])}
										aria-invalid={!!errors.audience}
									>
										<Select.Option value="" disabled selected={!field.value?.[0]}>
											{t('bookingfrontend.choose target audience')}
										</Select.Option>
										{audience?.map(item => (
											<Select.Option key={item.id} value={item.id}>
												{item.name}
											</Select.Option>
										))}
									</Select>
									{errors.audience && (
										<ValidationMessage>
											{errors.audience.message}
										</ValidationMessage>
									)}
								</Field>
							)}
						/>
					</div>

					<div className={`${styles.formGroup}`} style={{gridColumn: 1}} ref={participantsSectionRef}>
						<div className={styles.resourcesHeader}
							 style={{flexDirection: 'column', alignItems: 'flex-start'}}>
							<h4>{t('bookingfrontend.estimated number of participants')} <span className="required-asterisk">*</span></h4>
							{errors.agegroups?.['root']?.message && (
								<span className={styles.error}>{t(errors.agegroups?.['root']?.message)}</span>
							)}
						</div>

						{agegroups?.map((agegroup, index) => (
							<Fragment key={agegroup.id}>
								{/* Visible male count input */}
								<Controller
									name={`agegroups.${index}.male`}
									control={control}
									defaultValue={existingApplication?.agegroups?.find(ag => ag.id === agegroup.id)?.male || 0}
									render={({field}) => (
										<Textfield
											type="number"
											label={agegroup.name}
											{...field}
											value={field.value === 0 ? '' : field.value}
											placeholder="0"
											min={0}
											inputMode="numeric"
											pattern="[0-9]*"
											description={agegroup.description}
											onChange={(e) => {
												const value = e.target.value === '' ? 0 : Number(e.target.value);
												field.onChange(value);
											}}
											error={errors.agegroups?.[0]?.message ? t(errors.agegroups?.[0]?.message) : undefined}
											className={styles.participantInput}
										/>
									)}
								/>
								{/* Hidden fields for other required data */}
								<Controller
									name={`agegroups.${index}.id`}
									control={control}
									defaultValue={agegroup.id}
									render={({field}) => <input type="hidden" {...field} />}
								/>
								<Controller
									name={`agegroups.${index}.female`}
									control={control}
									defaultValue={0}
									render={({field}) => <input type="hidden" {...field} />}
								/>
								<Controller
									name={`agegroups.${index}.name`}
									control={control}
									defaultValue={agegroup.name}
									render={({field}) => <input type="hidden" {...field} />}
								/>
								<Controller
									name={`agegroups.${index}.description`}
									control={control}
									defaultValue={agegroup.description || ''}
									render={({field}) => <input type="hidden" {...field} value={field.value || ''}/>}
								/>
								<Controller
									name={`agegroups.${index}.sort`}
									control={control}
									defaultValue={agegroup.sort}
									render={({field}) => <input type="hidden" {...field} />}
								/>
							</Fragment>
						))}


					</div>

					<div className={`${styles.formGroup} ${styles.wide}`}>
						<Details data-color={'neutral'}>
							<Details.Summary>
								{t('bookingfrontend.additional_information')}
							</Details.Summary>
							<Details.Content style={{backgroundColor: "inherit"}} className={styles.eventForm}>
								<div className={`${styles.formGroup}`} style={{gridColumn: 1}}>
									<Controller
										name="homepage"
										control={control}
										render={({field}) => (
											<Textfield
												label={t('bookingfrontend.homepage')}
												{...field}
												error={errors.homepage?.message}
												placeholder={t('bookingfrontend.event/activity homepage')}
											/>
										)}
									/>
								</div>
								<div className={`${styles.formGroup}`} style={{gridColumn: 1}}>
									<Controller
										name="description"
										control={control}
										render={({field}) => (
											<Textfield
												label={t('bookingfrontend.description')}
												{...field}
												multiline={true}
												rows={3}
												error={errors.description?.message}
												placeholder={t('bookingfrontend.event/activity description')}
											/>
										)}
									/>
								</div>
								<div className={`${styles.formGroup}`} style={{gridColumn: 1}}>
									<Controller
										name="equipment"
										control={control}
										render={({field}) => (
											<Textfield
												label={t('bookingfrontend.equipment text')}
												{...field}
												multiline={true}
												rows={3}
												error={errors.equipment?.message}
												placeholder={t('bookingfrontend.equipment text')}
											/>
										)}
									/>
								</div>
								<div className={`${styles.formGroup}`} style={{gridColumn: 1}}>
									<div className={styles.resourcesHeader}>
										<h4>{t('bookingfrontend.documents')}</h4>
									</div>

									{existingApplication ? (
										// Show existing documents and direct upload for existing applications
										<>
											{existingApplication.documents?.length > 0 && (
												<div className={styles.documentsList}>
													{existingApplication.documents.map(doc => (
														<div key={doc.id} className={styles.documentItem}>
															<span>{doc.name}</span>
															<Button
																variant="tertiary"
																data-color={'danger'}
																// color="danger"
																data-size={'sm'}
																onClick={() => deleteDocumentMutation.mutate(doc.id, {
																	onSuccess: () => {
																		setHasExternalChanges(true);
																	}
																})}
																loading={deleteDocumentMutation.isPending}
															>
																{t('common.delete')}
															</Button>
														</div>
													))}
												</div>
											)}

											<input
												type="file"
												multiple
												value={""}
												onChange={(e) => {
													if (e.target.files && existingApplication.id) {
														const formData = new FormData();
														Array.from(e.target.files).forEach(file => {
															formData.append('files[]', file);
														});

														uploadDocumentMutation.mutate({
															id: existingApplication.id,
															files: formData
														}, {
															onSuccess: () => {
																setHasExternalChanges(true);
															}
														});
													}
												}}
											/>
											{uploadDocumentMutation.isPending && (
												<Spinner aria-label={t('common.uploading')}/>
											)}
										</>
									) : (
										// For new applications, just store the files to be uploaded after creation
										<>
											<input
												type="file"
												multiple
												onChange={(e) => setFilesToUpload(e.target.files)}
											/>
											{filesToUpload && (
												<div className={styles.selectedFiles}>
													{Array.from(filesToUpload).map((file, index) => (
														<div key={index} className={styles.selectedFileItem}>
															{file.name}
														</div>
													))}
												</div>
											)}
											{isUploadingFiles && (
												<Spinner aria-label={t('common.uploading')}/>
											)}
										</>
									)}
								</div>

							</Details.Content>
						</Details>
					</div>

			</section>
			</MobileDialog>
		</form>
	);
};

export default ApplicationCrudWrapper;