import {ReactElement} from 'react';
import {HeaderGroup, flexRender} from '@tanstack/react-table';
import {ColumnDef, TableProps} from './table.types';
import {SortButton} from './subcomponents/sort-button';
import styles from './table.module.scss';

interface TableHeaderProps<T> {
    headerGroups: HeaderGroup<T>[];
    gridTemplateColumns: string;
    renderExpandedContent?: boolean;
    icon?: boolean;
    iconPadding?: TableProps<T>['iconPadding'];
}

function TableHeader<T>(props: TableHeaderProps<T>): ReactElement {
    const {headerGroups, gridTemplateColumns, icon, iconPadding} = props;

    return (
            <>{headerGroups.map(headerGroup => (
                <h4
                    key={headerGroup.id}
                    className={`${styles.tableRow} ${styles.tableHeaderBig} ${styles.tableHeader}`}
                    style={{
                        display: 'contents'
                        // gridTemplateColumns: gridTemplateColumns,
                        // ...(icon ? {paddingLeft: iconPadding || '5.875rem'} : {})
                    }}
                >
                    {headerGroup.headers.map(header => {
                        const meta = (header.column.columnDef as ColumnDef<T>).meta;
                        const canSort = header.column.getCanSort();

                        return (
                            <div
                                key={header.id}
                                className={`${styles.tableHeaderCol} ${
                                    canSort ? styles.clickable : styles.notClickable
                                }`}
                                onClick={canSort ? header.column.getToggleSortingHandler() : undefined}
                            >
                                {!meta?.hideHeader && (
                                    <span className={styles.capitalize}>
                                        {flexRender(
                                            header.column.columnDef.header,
                                            header.getContext()
                                        )}
                                    </span>
                                )}
                                {canSort && (
                                    <SortButton
                                        direction={
                                            header.column.getIsSorted() === 'desc'
                                                ? 'desc'
                                                : header.column.getIsSorted() === 'asc'
                                                    ? 'asc'
                                                    : undefined
                                        }
                                    />
                                )}
                            </div>
                        );
                    })}
                    {props.renderExpandedContent && <div />}

                </h4>
            ))}
        </>
    );
}

export default TableHeader;