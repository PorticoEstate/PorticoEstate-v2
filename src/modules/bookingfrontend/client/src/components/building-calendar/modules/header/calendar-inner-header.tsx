import React, {Dispatch, FC, MutableRefObject} from 'react';
import {Badge, Button} from "@digdir/designsystemet-react";
import {ChevronLeftIcon, ChevronRightIcon, LayersIcon, PlusIcon, TableIcon, CalendarIcon} from "@navikt/aksel-icons";
import styles from './calendar-inner-header.module.scss';
import {IBuilding} from "@/service/types/Building";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import CalendarDatePicker from "@/components/date-time-picker/calendar-date-picker";
import FullCalendar from "@fullcalendar/react";
import ButtonGroup from "@/components/button-group/button-group";
import {
	useCalenderViewMode,
	useEnabledResources,
	useResourcesHidden,
} from "@/components/building-calendar/calendar-context";
import {DateTime} from "luxon";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {usePartialApplications} from "@/service/hooks/api-hooks";

interface CalendarInnerHeaderProps {

	setView: Dispatch<string>;
	setLastCalendarView: Dispatch<void>;
	view: string;
	building: IBuilding;
	calendarRef: MutableRefObject<FullCalendar | null>;
	createNew: () => void;
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
				default:
					setCurrentDate(currentDate.plus({days: 1}));
			}

	};

	const handleDateChange = (date: Date | null) => {
		if (!date) return;
		setCurrentDate(DateTime.fromJSDate(date));

	};

	return (
		<div className={styles.innerHeader}>
			<Button data-size={'sm'} icon={true} variant='tertiary'
					style={{}}
					className={`${styles.expandCollapseButton} ${resourcesHidden ? styles.closed : styles.open}`}
					onClick={() => setResourcesHidden(!resourcesHidden)}>


				{props.building.name}
				<ChevronLeftIcon
					className={`${styles.expandCollapseIcon} ${resourcesHidden ? styles.closed : styles.open}`}
					fontSize='2.25rem'/>
			</Button>
			<Button variant={'secondary'} data-size={'sm'}
					className={styles.mobileResourcesButton}
				// className={'captialize'}
					onClick={() => setResourcesHidden(!resourcesHidden)}>
					<LayersIcon fontSize="1.25rem" />{t('booking.select')} {t('bookingfrontend.resources')}
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
					{/*<Button variant={view === 'dayGridMonth' ? 'primary' : 'secondary'}  data-color={'brand1'} data-size={'sm'}*/}
					{/*        className={'captialize'}*/}
					{/*        onClick={() => setView('dayGridMonth')}>{t('bookingfrontend.month')}</Button>*/}
					</ButtonGroup>
				)}

			{
				calendarViewMode === 'calendar' &&
				<ButtonGroup data-color='accent' className={styles.modeSelect}>
					<Button variant={view !== 'listWeek' ? 'primary' : 'tertiary'}
							aria-active={'true'}
							aria-current={'true'} data-size={'sm'}
							className={'captialize subtle'} onClick={() => {
						props.setLastCalendarView()
					}}><CalendarIcon fontSize="1.25rem" /> <span
						className={styles.modeTitle}>{t('bookingfrontend.calendar_view')}</span></Button>
					<Button variant={view === 'listWeek' ? 'primary' : 'tertiary'}
							data-size={'sm'}
							className={'captialize subtle'} onClick={() => {
						props.setView('listWeek')
					}}><TableIcon fontSize="1.25rem" /> <span
						className={styles.modeTitle}>{t('bookingfrontend.list_view')}</span></Button>
				</ButtonGroup>
			}
			{
				calendarViewMode === 'calendar' &&
				<Button variant={(partials?.data?.list?.length || 0) === 0 ? 'primary' : 'secondary'} data-color={'accent'} onClick={props.createNew} data-size={'sm'} className={styles.orderButton}>
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
