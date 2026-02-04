import React, {Dispatch, FC, MutableRefObject} from 'react';
import {Badge, Button} from "@digdir/designsystemet-react";
import {ChevronLeftIcon, ChevronRightIcon, PlusIcon, TableIcon, CalendarIcon} from "@navikt/aksel-icons";
import styles from './calendar-inner-header.module.scss';
import {IBuilding} from "@/service/types/Building";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import FullCalendar from "@fullcalendar/react";
import ButtonGroup from "@/components/button-group/button-group";
import {
	useCalenderViewMode, useCurrentOrganization,
	useEnabledResources, useIsOrganization,
	useResourcesHidden,
} from "@/components/building-calendar/calendar-context";
import {DateTime} from "luxon";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {usePartialApplications} from "@/service/hooks/api-hooks";
import {useOrganization} from "@/service/hooks/organization";
import ResourceIcon from "@/icons/ResourceIcon";

interface CalendarInnerHeaderProps {

	setView: Dispatch<string>;
	setLastCalendarView: Dispatch<void>;
	view: string;
	building?: IBuilding;
	calendarRef: MutableRefObject<FullCalendar | null>;
	createNew?: () => void;
	currentDate: DateTime;
	setCurrentDate: (date: DateTime) => void;
}

const CalendarInnerHeader: FC<CalendarInnerHeaderProps> = (props) => {
	const t = useTrans();
	const {view, calendarRef, setView, currentDate, setCurrentDate} = props
	const {enabledResources} = useEnabledResources();
	const {resourcesHidden, setResourcesHidden} = useResourcesHidden();
	const calendarViewMode = useCalenderViewMode();
	const isMobile = useIsMobile();
	const isOrg = useIsOrganization();
	const currentOrganization = useCurrentOrganization();
	const {data: org} = useOrganization(currentOrganization);
	const partials = usePartialApplications();

	const c = calendarRef.current;

	const calendarApi = c?.getApi();
	// const calendarApi: CalendarApi | undefined = undefined;
	// if (!c) {
	// 	return null;
	// }
	// const currentDate = calendarApi ? calendarApi.getDate() : new Date();

	const handlePrevClick = () => {

		// Handle different view types
		switch (view) {
			case 'timeGridDay':
				setCurrentDate(currentDate.minus({days: 1}));
				break;
			case 'timeGridWeek':
				setCurrentDate(currentDate.minus({weeks: 1}));
				break;
			case 'dayGridMonth':
				setCurrentDate(currentDate.minus({months: 1}));
				break;
			default:
				setCurrentDate(currentDate.minus({days: 1}));
		}

	};

	const handleNextClick = () => {

			// Handle different view types
			switch (view) {
				case 'timeGridDay':
					setCurrentDate(currentDate.plus({days: 1}));
					break;
				case 'timeGridWeek':
					setCurrentDate(currentDate.plus({weeks: 1}));
					break;
				case 'dayGridMonth':
					setCurrentDate(currentDate.plus({months: 1}));
					break;
				default:
					setCurrentDate(currentDate.plus({days: 1}));
			}

	};

	const handleDateChange = (date: Date | null) => {
		if (!date) return;
		setCurrentDate(DateTime.fromJSDate(date));

	};

	const handleTodayClick = () => {
		setCurrentDate(DateTime.now());
	};

	return (
		<div className={styles.innerHeader}>
			<Button data-size={'sm'} icon={true} variant='tertiary'
					style={{}}
					className={`${styles.expandCollapseButton} ${resourcesHidden ? styles.closed : styles.open}`}
					onClick={() => setResourcesHidden(!resourcesHidden)}>


				{!isOrg && props.building && props.building.name}
				{isOrg && org && org.name}
				<ChevronLeftIcon
					className={`${styles.expandCollapseIcon} ${resourcesHidden ? styles.closed : styles.open}`}
					fontSize='2.25rem'/>
			</Button>
			<Button variant={'secondary'} data-size={'sm'}
					className={styles.mobileResourcesButton}
				// className={'captialize'}
					onClick={() => setResourcesHidden(!resourcesHidden)}>
					<ResourceIcon fontSize="1.25rem" />{t('booking.select')} {t('bookingfrontend.resources')}
				<Badge count={enabledResources.size} data-size={"md"} color={"danger"}></Badge>
			</Button>

			<div className={styles.datePicker}>
				<Button data-size={'sm'} icon={true} variant='tertiary' style={{borderRadius: "50%"}}
						onClick={handlePrevClick}
				>
					<ChevronLeftIcon style={{
						height: '100%',
						width: '100%'
					}}/>
				</Button>
				<CalendarDatePicker
					currentDate={currentDate.toJSDate()}
					view={view}
					onDateChange={handleDateChange}
					// timeIntervals={30}
					// dateFormat="dd.MM.yyyy HH:mm"
				/>
				<Button icon={true} data-size={'sm'} variant='tertiary' style={{borderRadius: "50%"}}
						onClick={handleNextClick}
				>
					<ChevronRightIcon style={{
						height: '100%',
						width: '100%'
					}}/>
				</Button>

			</div>
			<Button data-size={'sm'} variant='secondary'
					onClick={handleTodayClick}
					className={styles.todayButton}
			>
				{t('common.today')}
				{/*I Dag*/}
			</Button>
			{/* Hide day/week buttons when in calendar mode on mobile */}
			{!(isMobile && calendarViewMode === 'calendar') && (
				<ButtonGroup data-color='accent' className={styles.modeSelectTime}>
					<Button variant={view === 'timeGridDay' ? 'primary' : 'tertiary'} data-color={'accent'}
							data-size={'sm'}
							className={'captialize subtle'}
							onClick={() => setView('timeGridDay')}>{t('bookingfrontend.day')}</Button>
					<Button variant={view === 'timeGridWeek' ? 'primary' : 'tertiary'} data-color={'accent'}
							data-size={'sm'}
							className={'captialize subtle'}
							onClick={() => setView('timeGridWeek')}>{t('bookingfrontend.week')}</Button>
					{/*<Button variant={view === 'dayGridMonth' ? 'primary' : 'tertiary'} data-color={'accent'}*/}
					{/*		data-size={'sm'}*/}
					{/*		className={'captialize subtle'}*/}
					{/*		onClick={() => setView('dayGridMonth')}>{t('bookingfrontend.month')}</Button>*/}
					</ButtonGroup>
				)}

			{
				calendarViewMode === 'calendar' &&
				<ButtonGroup data-color='accent' className={styles.modeSelect}>
					<Button variant={view !== 'listWeek' && view !== 'listDay' ? 'primary' : 'tertiary'}
							aria-current={'true'} data-size={'sm'}
							className={'captialize subtle'} onClick={() => {
						props.setLastCalendarView()
					}}><CalendarIcon fontSize="1.25rem" /> <span
						className={styles.modeTitle}>{t('bookingfrontend.calendar_view')}</span></Button>
					<Button variant={view === 'listWeek' || view === 'listDay' ? 'primary' : 'tertiary'}
							data-size={'sm'}
							className={'captialize subtle'} onClick={() => {
						props.setView(isMobile ? 'listDay' : 'listWeek')
					}}><TableIcon fontSize="1.25rem" /> <span
						className={styles.modeTitle}>{t('bookingfrontend.list_view')}</span></Button>
				</ButtonGroup>
			}
			{
				!isOrg && calendarViewMode === 'calendar' &&
				<Button
					variant={(partials?.data?.list?.length || 0) === 0 ? 'primary' : 'secondary'}
					data-color={'accent'}
					onClick={props.createNew}
					data-size={'sm'}
					className={styles.orderButton}
					disabled={!props.building || props.building.deactivate_application}
				>
					{/*<Link href={applicationURL}>*/}
					{t('bookingfrontend.new application')}

					<PlusIcon fontSize="1.25rem" />
					{/*</Link>*/}

				</Button>
			}

		</div>
	);
}

export default CalendarInnerHeader
