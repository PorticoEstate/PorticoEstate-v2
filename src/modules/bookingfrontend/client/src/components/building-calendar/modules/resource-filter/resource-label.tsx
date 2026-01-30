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
import {useIsOrganization} from "@/components/building-calendar/calendar-context";
import {useBuilding} from "@/service/api/building";



type ResourceLabelType = Pick<Partial<CalendarResourceFilterOption>, 'buildingId' | 'value' | "deactivated"> & Pick<CalendarResourceFilterOption, 'label'>

const ResourceLabel: FC<{ resource: ResourceLabelType; onInfo?: () => void }> = ({resource, onInfo}) => {
	const isMobile = useIsMobile();
	const t = useTrans();
	const isOrg = useIsOrganization();
	const {data: building} = useBuilding(resource.buildingId)


	return (
		<div className={`${styles.resourceLabel} text-normal`}>
			<div className={styles.resourceLabelWrapper}>
				{resource.value !== undefined && <ColourCircle resourceId={+resource.value} size={'medium'}/>}
				<div className={styles.resourceTitle}>
					<span>{resource.label}</span>
					{resource.deactivated && (
						<span className={styles.deactivatedText}>
						({t('bookingfrontend.booking_unavailable')})
					</span>
					)}
					{building && isOrg && (
						<span className={'text-tiny'}>
							{building.name}
						</span>
					)}
				</div>

			</div>
			{!isMobile && onInfo !== undefined && (
				<Button variant={'tertiary'} data-size={'sm'} onClick={onInfo}>
					<InformationSquareIcon fontSize={'1.5rem'}/>
				</Button>
			)}
		</div>
	)
};

export default ResourceLabel;