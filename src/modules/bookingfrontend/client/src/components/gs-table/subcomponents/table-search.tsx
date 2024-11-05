// table-search.tsx
import { ChangeEvent, useState, useEffect } from 'react';
import { Table } from '@tanstack/react-table';
import styles from './table-search.module.scss';

interface TableSearchProps<T> {
    table: Table<T>;
    placeholder?: string;
    className?: string;
}

function TableSearch<T>({
                            table,
                            placeholder = 'Search...',
                            className,
                        }: TableSearchProps<T>) {
    const [value, setValue] = useState('');

    useEffect(() => {
        const debounceTimeout = setTimeout(() => {
            table.setGlobalFilter(value);
        }, 300);

        return () => clearTimeout(debounceTimeout);
    }, [value, table]);

    return (
        <div className={styles.searchContainer}>
            <input
                type="search"
                value={value}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setValue(e.target.value)}
                placeholder={placeholder}
                className={`${styles.searchInput} ${className || ''}`}
            />
            <svg
                className={styles.searchIcon}
                width="20"
                height="20"
                viewBox="0 0 20 20"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
            >
                <path
                    d="M9 17A8 8 0 1 0 9 1a8 8 0 0 0 0 16zm8.932 2.639l-3.3-3.3"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                />
            </svg>
        </div>
    );
}

export default TableSearch;