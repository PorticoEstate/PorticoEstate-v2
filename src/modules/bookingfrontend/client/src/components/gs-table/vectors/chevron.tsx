import { FC } from 'react';
import { SVGIconProps } from './vector.types';
import styles from './chevron.module.scss';

interface ChevronProps extends SVGIconProps {
    open?: boolean;
}

export const Chevron: FC<ChevronProps> = (props) => {
    const { open } = props;
    return (
        <svg
            viewBox="0 0 20 14"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            {...props}
            className={`${styles.chevron} ${open ? styles.open : ''}`}
            style={{ height: '0.875rem', ...props.style }}
        >
            <path d="M18 13L10 3L2 13" stroke={props.color || '#394F5A'} strokeWidth="3" />
        </svg>
    );
};
