import { FC } from 'react';
import styles from './sort-button.module.scss';
import { Arrow } from '../vectors/arrow';

interface SortButtonProps {
    direction?: 'asc' | 'desc';
}

export const SortButton: FC<SortButtonProps> = (props) => {
    return (
        <div className={styles.container}>
            <Arrow className={props.direction === 'desc' ? styles.active : ''} upsideDown />
            <Arrow className={props.direction === 'asc' ? styles.active : ''} />
        </div>
    );
};
