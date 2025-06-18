import {FC} from "react";
import {
	CalendarResourceFilterOption
} from "@/components/building-calendar/modules/resource-filter/calender-resource-filter";

import styles from './calender-resource-filter.module.scss';
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Button} from "@digdir/designsystemet-react";
import {InformationSquareIcon} from "@navikt/aksel-icons";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";


const ResourceLabel: FC<{resource: CalendarResourceFilterOption; onInfo: () => void}> = ({resource, onInfo}) => {
	const isMobile = useIsMobile();
	const t = useTrans();

	return (
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
				<Button variant={'tertiary'} data-size={'sm'} onClick={onInfo}>
					<InformationSquareIcon fontSize={'1.5rem'}/>
				</Button>
			)}
		</div>
	)
};

export default ResourceLabel;