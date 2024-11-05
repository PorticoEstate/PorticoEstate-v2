import { FC } from 'react';
import { SVGIconProps } from './vector.types';
import styles from './arrow.module.scss';

interface ArrowProps extends SVGIconProps {
    upsideDown?: boolean;
}

export const Arrow: FC<ArrowProps> = ({ upsideDown, className, ...props }) => {
    return (
        <svg
            viewBox="0 0 16 22"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            {...props}
            className={`${styles.arrow} ${upsideDown ? styles.upsideDown : ''} ${className || ''}`}
            style={{ height: '1rem', ...props.style }}
        >
            <path
                d="M7 21C7 21.5523 7.44772 22 8 22C8.55228 22 9 21.5523 9 21L7 21ZM8.70711 0.292892C8.31658 -0.0976315 7.68342 -0.0976315 7.29289 0.292892L0.928932 6.65685C0.538408 7.04738 0.538408 7.68054 0.928932 8.07107C1.31946 8.46159 1.95262 8.46159 2.34315 8.07107L8 2.41421L13.6569 8.07107C14.0474 8.46159 14.6805 8.46159 15.0711 8.07107C15.4616 7.68054 15.4616 7.04738 15.0711 6.65685L8.70711 0.292892ZM9 21L9 1L7 1L7 21L9 21Z"
                fill={props.color || '#E2DDDB'}
            />
        </svg>
    );
};
