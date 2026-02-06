import React, {FC, useState} from 'react';
import styles from "./resource-circles.module.scss";
import {IShortResource} from "@/service/pecalendar.types";
import ColourCircle from "@/components/building-calendar/modules/colour-circle/colour-circle";
import {Button, List} from "@digdir/designsystemet-react";
import ResourceIcon from "@/icons/ResourceIcon";

interface ResourceCirclesProps {
    resources: IShortResource[];
    maxCircles: number;
    size: 'medium' | 'small';
    expandable?: boolean;
    onClick?: () => void;
    isExpanded?: boolean;
}

const ResourceCircles: FC<ResourceCirclesProps> = (props) => {
    const {resources, maxCircles, size} = props;
    const [expanded, setExpanded] = useState<boolean>(false);
    const totalResources = resources.length;
    const circlesToShow = resources.slice(0, maxCircles);
    const remainingCount = totalResources - maxCircles;


    if (resources.length === 1) {
        return <span><ColourCircle resourceId={resources[0].id}/> {resources[0].name}</span>;
    }

    // return

    const rendered = (props.isExpanded ||expanded) ? (<List.Unordered
        style={{
            listStyle: 'none',
            padding: 0
        }}>

        {resources.map((res) => <List.Item key={res.id} className={"text-body"}><ColourCircle resourceId={res.id}/> {res.name}</List.Item>)}
    </List.Unordered>) : (
        <div className={styles.colorCircles}>
            <ResourceIcon fontSize="1.25rem" />
            {circlesToShow.map((res, index) => (
                <ColourCircle resourceId={res.id} key={index} className={styles.colorCircle} size={size}/>
            ))}
            {remainingCount === 1 && (
                <ColourCircle
                    resourceId={resources[maxCircles].id}
                    key={maxCircles}
                    className={styles.colorCircle}
                    size={size}
                />
            )}
            {remainingCount > 1 && <span className={styles.remainingCount}>+{remainingCount}</span>}
        </div>
    )


    if (props.expandable) {
        return (<Button onClick={() => props.onClick ? props.onClick() : setExpanded(!expanded) }>{rendered}</Button>)

    }
    return rendered;
}

export default ResourceCircles


