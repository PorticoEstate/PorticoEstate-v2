import React, { FC } from 'react';
import styles from './expandable-content.module.scss';
import SlideDown from '@teskon/react-slidedown';

interface ExpandableContentProps extends React.HTMLAttributes<HTMLDivElement> {
    as?: keyof JSX.IntrinsicElements | React.ComponentType<any>;
    closed?: boolean;
    transitionOnAppear?: boolean;
    open?: boolean;
}

const ExpandableContent: FC<ExpandableContentProps> = ({ children, open, ...props }) => {
    return (
        //@ts-ignore -- Ignore because package developer has used a bad way of migrating from class to functional component
        <SlideDown {...props} className={`${styles.dropdownSlider} ${props.className}`}>
            {open && children}
        </SlideDown>
    );
};

export default ExpandableContent;
