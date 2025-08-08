import {ReactElement, useState, useEffect} from 'react';
import {HeaderGroup, flexRender} from '@tanstack/react-table';
import {ColumnDef, TableProps} from './table.types';
import {SortButton} from './subcomponents/sort-button';
import styles from './table.module.scss';
import { Select, Field } from '@digdir/designsystemet-react';

interface TableHeaderProps<T> {
    headerGroups: HeaderGroup<T>[];
    gridTemplateColumns?: string;
    renderExpandedContent?: boolean;
    icon?: boolean;
    iconPadding?: TableProps<T>['iconPadding'];
    isMobile?: boolean;
}

function TableHeader<T>(props: TableHeaderProps<T>): ReactElement {
    const {headerGroups, gridTemplateColumns, icon, iconPadding, isMobile = false} = props;

    // Find the first non-hidden column with data that's sortable to use as default
    const findDefaultSortColumn = () => {
        for (const headerGroup of headerGroups) {
            for (const header of headerGroup.headers) {
                // Skip icon or selection columns
                const meta = (header.column.columnDef as ColumnDef<T>).meta;
                if (meta?.size === 'icon' || header.column.id === 'select') continue;

                // Skip hidden columns
                if (meta?.hideHeader) continue;

                // If we find a sortable column, use it
                if (header.column.getCanSort()) {
                    return header.column.id;
                }
            }
        }
        return null;
    };

    const [activeColumn, setActiveColumn] = useState<string | null>(null);

    // Set default active column on first render for mobile
    useEffect(() => {
        if (isMobile && !activeColumn) {
            setActiveColumn(findDefaultSortColumn());
        }
    }, [isMobile, headerGroups]);

    // Function to handle dropdown toggle
    const toggleDropdown = (headerId: string) => {
        if (activeColumn === headerId) {
            setActiveColumn(null);
        } else {
            setActiveColumn(headerId);
        }
    };

    return (
        <>
            {/* Desktop Header - only show if not mobile */}
            {!isMobile && headerGroups.map(headerGroup => (
                <h4
                    key={headerGroup.id}
                    className={`${styles.tableRow} ${styles.tableHeaderBig} ${styles.tableHeader}`}
                    style={{
                        display: 'contents'
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
                                data-column-id={header.column.id}
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

            {/* Mobile Header Dropdown - only show if mobile */}
            {isMobile && <div className={styles.tableHeaderSmall}>
                {headerGroups.map(headerGroup => {
                    // Filter out columns that can't be sorted or are hidden
                    const sortableColumns = headerGroup.headers.filter(header => {
                        const meta = (header.column.columnDef as ColumnDef<T>).meta;
                        return header.column.getCanSort() && !meta?.hideHeader && meta?.size !== 'icon' && header.column.id !== 'select';
                    });

                    if (sortableColumns.length === 0) return null;

                    // Find the currently selected column for displaying sort status
                    const selectedColumn = sortableColumns.find(header => header.column.id === activeColumn);
                    const currentSort = selectedColumn?.column.getIsSorted();

                    // Get column names for the dropdown
                    const columnOptions = sortableColumns.map(header => ({
                        id: header.column.id,
                        name: typeof header.column.columnDef.header === 'string'
                            ? header.column.columnDef.header
                            : header.column.id,
                        isSorted: header.column.getIsSorted()
                    }));

                    return (
                        <div key={headerGroup.id} className={styles.tableHeaderCol}>
                            <Field className={styles.sortSelectField}>
                                <Select
                                    className={styles.sortSelect}
                                    value={activeColumn || ''}
                                    onChange={(e) => {
                                        const selectedId = e.target.value;

                                        // Just find the header and set as active, don't auto-sort
                                        const header = headerGroup.headers.find(h => h.column.id === selectedId);

                                        setActiveColumn(selectedId);
                                    }}
                                >
                                    {columnOptions.map(column => (
                                        <Select.Option key={column.id} value={column.id}>
                                            {column.name}
                                        </Select.Option>
                                    ))}
                                </Select>
                            </Field>

                            {/* Always show sort button if column is selected */}
                            {selectedColumn && (
                                <button
                                    className={styles.sortToggleButton}
                                    onClick={() => {
                                        // Toggle sort direction
                                        selectedColumn.column.toggleSorting();
                                    }}
                                >
                                    <SortButton direction={currentSort as 'asc' | 'desc' | undefined} />
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>}
        </>
    );
}

export default TableHeader;