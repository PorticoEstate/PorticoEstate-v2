import {FC} from 'react';
import {IconProp} from "@fortawesome/fontawesome-svg-core";
import styles from "@/components/building-page/building-header.module.scss";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {Heading} from "@digdir/designsystemet-react";

interface PageHeaderProps {
    title: string | JSX.Element;
    icon?: IconProp;
    className?: string
}

const PageHeader: FC<PageHeaderProps> = (props) => {
    return (
        <section className={`${styles.buildingHeader} ${props.className || ''}`}>
            <div className={styles.buildingName}>
                <Heading level={2} data-size={'xl'}>
                    {props.icon && (
                    <FontAwesomeIcon style={{fontSize: '22px'}} icon={props.icon}/>)}
                    {props.title}
                </Heading>
            </div>
        </section>
    );
}

export default PageHeader


