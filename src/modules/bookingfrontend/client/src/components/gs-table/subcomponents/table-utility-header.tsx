// table-utility-header.tsx
import { ReactNode } from 'react';
import styles from './table-utility-header.module.scss';

export interface TableUtilityHeaderProps {
    left?: ReactNode;
    right?: ReactNode;
}

function TableUtilityHeader({ left, right }: TableUtilityHeaderProps) {
    if (!left && !right) return null;

    return (
        <div className={styles.utilityHeader}>
            <div className={styles.leftSection}>
                {left}
            </div>
            <div className={styles.rightSection}>
                {right}
            </div>
        </div>
    );
}

export default TableUtilityHeader;