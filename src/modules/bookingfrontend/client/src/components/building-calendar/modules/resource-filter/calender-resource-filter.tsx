import React, {FC, memo, useEffect, useMemo, useState} from 'react';
import styles from './calender-resource-filter.module.scss';
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Checkbox, Button, Fieldset, Radio} from "@digdir/designsystemet-react";
import {useEnabledResources, useTempEvents} from "@/components/building-calendar/calendar-context";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import MobileDialog from "@/components/dialog/mobile-dialog";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {InformationSquareIcon} from "@navikt/aksel-icons";
import ResourceInfoModal
	from "@/components/building-calendar/modules/resource-filter/resource-info-popper/resource-info-popper";
import {useBuildingResources} from "@/service/api/building";
import ResourceLabel from "@/components/building-calendar/modules/resource-filter/resource-label";
import {FreeTimeSlotsResponse} from "@/service/hooks/api-hooks";
import {useQueryClient} from "@tanstack/react-query";
import {ResourceUsesTimeSlots} from "@/components/building-calendar/util/calender-helpers";


interface GroupedResources {
	slotted: CalendarResourceFilterOption[];
	normal: CalendarResourceFilterOption[];
}

export interface CalendarResourceFilterOption {
	value: string;
	label: string;
	deactivated?: boolean;
}

interface CalendarResourceFilterProps {
	open: boolean;
	transparent: boolean;
	setOpen: (open: boolean) => void;
	buildingId: string | number;
}


const CalendarResourceFilter: FC<CalendarResourceFilterProps> = ({
																	 open,
																	 transparent,
																	 setOpen,
																	 buildingId
																 }) => {
	const isMobile = useIsMobile();
	const t = useTrans();
	const [popperResource, setPopperResource] = useState<CalendarResourceFilterOption | null>(null);
	const {setEnabledResources, enabledResources} = useEnabledResources();
	const queryClient = useQueryClient();
	const {data: resources} = useBuildingResources(buildingId)


	const resourcesWithSlots = useMemo(() => {
		// Get all cached queries that match the pattern
		const freeTimeQueries = queryClient.getQueriesData<FreeTimeSlotsResponse>({
			queryKey: ['buildingFreeTime', buildingId]
		});

		const slottedResources = new Set<string>();

		// Check each cached query for resources with free time slots
		freeTimeQueries.forEach(([_key, data]) => {
			if (data) {
				Object.keys(data).forEach(resourceId => {
					if (data[resourceId]?.length > 0) {
						slottedResources.add(resourceId);
					}
				});
			}
		});

		return slottedResources;
	}, [queryClient, buildingId]);

	// console.log("resourcesWithSlots", resourcesWithSlots)

	const resourceOptions = useMemo<CalendarResourceFilterOption[]>(() => {
		return (resources || []).map((resource, index) => ({
			value: resource.id.toString(),
			label: resource.name,
			deactivated: resource.deactivate_application
		}));
	}, [resources]);


	const groupedResources = useMemo<GroupedResources>(() => {
		const slotted: CalendarResourceFilterOption[] = [];
		const normal: CalendarResourceFilterOption[] = [];

		(resources || []).forEach(resource => {
			const option = {
				value: resource.id.toString(),
				label: resource.name,
				deactivated: resource.deactivate_application
			};

			if (ResourceUsesTimeSlots(resource)) {
				slotted.push(option);
			} else {
				normal.push(option);
			}
		});
		return {slotted, normal};

		// // If there are any slotted resources selected, only show those
		// // If there are any normal resources selected, only show those
		// // If nothing is selected, default to showing normal resources
		// const hasSlottedSelected = slotted.some(r => enabledResources.has(r.value));
		// const hasNormalSelected = normal.some(r => enabledResources.has(r.value));
		//
		// if (hasSlottedSelected) {
		// 	return { slotted, normal: [] };
		// } else if (hasNormalSelected || !hasSlottedSelected) {
		// 	return { slotted: [], normal };
		// }
		//
		// return { slotted, normal: [] };
	}, [resources]);

	useEffect(() => {
		// Only run this once when component mounts
		setEnabledResources(prevEnabled => {
			// If nothing is enabled yet, default to enabling all normal resources
			if (prevEnabled.size === 0) {
				if (groupedResources) {
					if (groupedResources.normal.length < groupedResources.slotted.length) {
						return new Set([groupedResources.slotted?.[0]?.value.toString()].filter(Boolean));
					}
					return new Set(groupedResources.normal.map(r => r.value.toString()));
				}
				const normalResources = (resources || []).filter((res, index) => !ResourceUsesTimeSlots(res));
				return new Set(normalResources.map(r => r.id.toString()));
			}
			return prevEnabled;
		});
	}, [resources, setEnabledResources, groupedResources]);


	const onToggle = (resourceId: string, isSlotted: boolean) => {
		setEnabledResources(prevEnabled => {
			if (isSlotted) {
				// If selecting a slotted resource, clear everything and only select this one
				return new Set([resourceId]);
			} else {
				// If selecting a normal resource:
				const newEnabled = new Set(prevEnabled);

				// First check if there's any slotted resource selected
				const hasSlottedResource = groupedResources.slotted.some(r =>
					prevEnabled.has(r.value)
				);

				// If there was a slotted resource selected, clear everything first
				if (hasSlottedResource) {
					newEnabled.clear();
				}

				// Then toggle the normal resource
				if (newEnabled.has(resourceId)) {
					newEnabled.delete(resourceId);
				} else {
					newEnabled.add(resourceId);
				}

				return newEnabled;
			}
		});
	};

	const onToggleAll = (resources: CalendarResourceFilterOption[]) => {
		setEnabledResources(prevEnabled => {
			// Check if all normal resources are currently selected
			const allSelected = resources.every(r => prevEnabled.has(r.value));

			if (allSelected) {
				// If all are selected, clear only the normal resources
				const newEnabled = new Set(prevEnabled);
				resources.forEach(r => newEnabled.delete(r.value));
				return newEnabled;
			} else {
				// If not all selected, clear everything (including any slotted resource)
				// and select all normal resources
				return new Set(resources.map(r => r.value));
			}
		});
	};

	// console.log('enabled', enabledResources, resources)
	const content = (
		<div
			className={`${styles.resourceToggleContainer} ${!open ? styles.hidden : ''}  ${transparent ? styles.transparent : ''}`}
		>


			{groupedResources.slotted.length > 0 && (
				<Fieldset>
					<Fieldset.Legend style={{marginLeft: '0.5rem'}}>{t('bookingfrontend.timeslot_resources')}</Fieldset.Legend>
					<Fieldset.Description style={{marginLeft: '0.5rem'}}>
						{t('bookingfrontend.timeslot_resources_description')}
					</Fieldset.Description>
					{groupedResources.slotted.map(resource => (
						<div key={resource.value}
							 className={`${styles.resourceItem} ${enabledResources.has(resource.value) ? styles.active : ''} ${resource.deactivated ? styles.deactivated : ''}`}>

							<Radio
								key={resource.value}
								value={resource.value}
								name="slotted-resource"
								checked={enabledResources.has(resource.value) || false}
								onChange={() => onToggle(resource.value, true)}
								label={
									<div className={`${styles.resourceLabel} text-normal`}>
										<div>
											<ColourCircle resourceId={+resource.value} size={'medium'}/>
											<span>{resource.label}</span>
											{resource.deactivated && (
												<span className={styles.deactivatedText}>
													({t('bookingfrontend.booking_unavailable')})
												</span>
											)}
										</div>
										{!isMobile && (
											<Button
												variant={'tertiary'}
												data-size={'sm'}
												onClick={() => setPopperResource(resource)}
											>
												<InformationSquareIcon fontSize={'1.5rem'}/>
											</Button>
										)}
									</div>
								}
								className={styles.resourceCheckbox}
							/>
						</div>
					))}
				</Fieldset>
			)}
			{groupedResources.normal.length > 0 && (
				<Fieldset>
					<Fieldset.Legend style={{marginLeft: '0.5rem'}}>{t('bookingfrontend.calendar_resources')}</Fieldset.Legend>
					<div className={styles.toggleAllContainer}>
						<Checkbox
							data-size={'sm'}
							value={'choose_all'}
							id={`resource-all`}
							checked={groupedResources.normal.every(r => enabledResources.has(r.value))}
							onChange={() => onToggleAll(groupedResources.normal)}
							label={t('bookingfrontend.select_all')}
							className={styles.resourceCheckbox}
						/>
					</div>
					{groupedResources.normal.map(resource => (
						<div key={resource.value}
							 className={`${styles.resourceItem} ${enabledResources.has(resource.value) ? styles.active : ''} ${resource.deactivated ? styles.deactivated : ''}`}>
							<Checkbox
								value={resource.value}
								id={`resource-${resource.value}`}
								checked={enabledResources.has(resource.value)}
								onChange={() => onToggle(resource.value, false)}
								label={<ResourceLabel resource={resource} onInfo={() => setPopperResource(resource)}/>}
								className={styles.resourceCheckbox}
							/>
						</div>
					))}
				</Fieldset>
			)}



			{!isMobile && (
				<ResourceInfoModal resource_id={popperResource?.value || null}
								   resource_name={popperResource?.label || null} onClose={() => {
					setPopperResource(null);
				}}/>
			)}
		</div>
	);

	if (isMobile) {
		return (
			<div className={styles.resourceToggleContainer}>
				<MobileDialog open={open} onClose={() => setOpen(false)}>{content}</MobileDialog>
			</div>
		)
	}

	return content
};

export default memo(CalendarResourceFilter);


