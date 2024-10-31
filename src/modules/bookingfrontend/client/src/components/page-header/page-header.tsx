import {FC} from 'react';
import {IconProp} from "@fortawesome/fontawesome-svg-core";
import styles from "@/components/building-page/building-header.module.scss";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";

interface PageHeaderProps {
    title: string;
    icon?: IconProp;
    className?: string
}

const PageHeader: FC<PageHeaderProps> = (props) => {
    return (
        <section className={`${styles.buildingHeader} mx-3 ${props.className || ''}`}>
            <div className={styles.buildingName}>
                <h2>
                    {props.icon && (
                    <FontAwesomeIcon style={{fontSize: '22px'}} icon={props.icon}/>)}
                    {props.title}
                </h2>
            </div>
        </section>
    );
}

export default PageHeader


