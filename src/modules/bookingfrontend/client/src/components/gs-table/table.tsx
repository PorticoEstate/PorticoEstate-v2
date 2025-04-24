'use client';
import {
    useReactTable,
    getCoreRowModel,
    getSortedRowModel,
    FilterFnOption,
    SortingState, RowSelectionState, FilterFn, getFilteredRowModel, VisibilityState,
    getPaginationRowModel
} from '@tanstack/react-table';
import {useState, useMemo, useEffect} from 'react';
import styles from './table.module.scss';
import type {ColumnDef, TableProps, TableStorageSettings} from './table.types';
import TableRow from "@/components/gs-table/row/table-row";
import TableHeader from "@/components/gs-table/table-header";
import TableUtilityHeader from "@/components/gs-table/subcomponents/table-utility-header";
import {rankItem} from '@tanstack/match-sorter-utils';
import TableSearch from "@/components/gs-table/subcomponents/table-search";
import ColumnToggle from "@/components/gs-table/subcomponents/column-toggle";
import TablePagination from "./subcomponents/table-pagination";
import TableExport from "@/components/gs-table/subcomponents/table-export";
import {useIsMobile} from "@/service/hooks/is-mobile";


// Fuzzy filter function
const fuzzyFilter: FilterFn<any> = (row, columnId, value, addMeta) => {
    // When there's no search term, show all rows
    if (!value || typeof value !== 'string') return true;

    const searchTerm = value.toLowerCase();

    // Get the value of the column
    const cellValue = row.getValue(columnId);

    // Handle different data types
    let textToSearch = '';

    if (typeof cellValue === 'number') {
        textToSearch = cellValue.toString();
    } else if (cellValue instanceof Date) {
        textToSearch = cellValue.toLocaleDateString();
    } else if (typeof cellValue === 'string') {
        textToSearch = cellValue;
    } else if (cellValue === null || cellValue === undefined) {
        return false;
    } else {
        textToSearch = cellValue.toString();
    }

    // Rank the item using match-sorter's rankItem
    const itemRank = rankItem(textToSearch.toLowerCase(), searchTerm);

    // Store the itemRank info
    addMeta({
        itemRank,
    });

    // Return if the item should be filtered in/out
    return itemRank.passed;
};


// Global filter function
const globalFilterFn: FilterFnOption<any> = (row, columnId, filterValue) => {
    const search = filterValue.toLowerCase();
    const value = row.getValue(columnId);

    if (typeof value === 'number') {
        return value.toString().includes(search);
    }

    if (value instanceof Date) {
        return value.toLocaleDateString().toLowerCase().includes(search) ||
            value.toLocaleString().toLowerCase().includes(search);
    }

    if (typeof value === 'string') {
        return value.toLowerCase().includes(search);
    }

    return false;
};

const getStorageKey = (storageId: string | undefined) => {
    if (!storageId) return null;
    return `table-settings-${storageId}`;
};


// Load settings from localStorage
const loadStoredSettings = (storageId: string | undefined): TableStorageSettings | null => {
    if (!storageId) return null;
    const key = getStorageKey(storageId);
    if (!key) return null;

    try {
        const stored = localStorage.getItem(key);
        return stored ? JSON.parse(stored) : null;
    } catch (error) {
        console.warn('Failed to load table settings from localStorage:', error);
        return null;
    }
};

// Save settings to localStorage
const saveSettings = (storageId: string | undefined, settings: TableStorageSettings) => {
    if (!storageId) return null;
    const key = getStorageKey(storageId);
    if (!key) return;

    try {
        localStorage.setItem(key, JSON.stringify(settings));
    } catch (error) {
        console.warn('Failed to save table settings to localStorage:', error);
    }
};

function Table<T>({
                      data,
                      columns,
                      storageId,
                      empty,
                      enableSorting = true,
                      renderExpandedContent,
                      renderRowButton,
                      icon,
                      iconPadding,
                      rowStyle,
                      defaultSort = [],
                      enableRowSelection = false,
                      enableMultiRowSelection = true,
                      onSelectionChange,
                      selectedRows,
                      utilityHeader,
                      enableSearch = false,
                      searchPlaceholder,
                      onSearchChange,
                      defaultColumnVisibility,
                      onColumnVisibilityChange,
                      pageSize: defaultPageSize = 10,
                      enablePagination = true,
                      exportFileName
                  }: TableProps<T>) {

    const isMobile = useIsMobile();
    const storedSettings = loadStoredSettings(storageId);
    const [sorting, setSorting] = useState<SortingState>(defaultSort);
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const [globalFilter, setGlobalFilter] = useState('');
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        storedSettings?.columnVisibility || defaultColumnVisibility || {}
    );
    const [pageSize, setPageSize] = useState(
        storedSettings?.pageSize || defaultPageSize
    );

    useEffect(() => {
        if (!storedSettings?.columnVisibility) {
            const initialVisibility = columns.reduce((acc, column) => {
                if (column.meta?.defaultHidden && (column as any)?.accessorKey) {
                    acc[(column as any).accessorKey as string] = false;
                }
                return acc;
            }, {} as VisibilityState);
            setColumnVisibility(initialVisibility);
        }
    }, [columns, storedSettings]);


    useEffect(() => {
        if (storageId) {
            saveSettings(storageId, {
                columnVisibility,
                pageSize,
            });
        }
    }, [columnVisibility, pageSize, storageId]);


    const handleColumnVisibilityChange = (updater: VisibilityState | ((state: VisibilityState) => VisibilityState)) => {
        const newState = typeof updater === 'function' ? updater(columnVisibility) : updater;
        setColumnVisibility(newState);
        onColumnVisibilityChange?.(newState);
    };

    const handlePageSizeChange = (newSize: number) => {
        setPageSize(newSize);
        table.setPageSize(newSize);
    };

    // Add selection column if enabled
    const tableColumns = useMemo(() => {
        if (!enableRowSelection) return columns;

        const selectionColumn: ColumnDef<T> = {
            id: 'select',
            meta: {size: 'icon', smallHideTitle: true},
            header: ({table}) => (
                enableMultiRowSelection ? (
                    <input
                        type="checkbox"
                        checked={table.getIsAllRowsSelected()}
                        // indeterminate={table.getIsSomeRowsSelected()}
                        onChange={table.getToggleAllRowsSelectedHandler()}
                    />
                ) : null
            ),
            cell: ({row}) => (
                <input
                    type="checkbox"
                    checked={row.getIsSelected()}
                    disabled={!row.getCanSelect()}
                    onChange={row.getToggleSelectedHandler()}
                />
            ),
        };

        return [selectionColumn, ...columns];
    }, [columns, enableRowSelection, enableMultiRowSelection]);


    const table = useReactTable({
        data,
        columns: tableColumns,
        state: {
            sorting,
            rowSelection: selectedRows || rowSelection,
            globalFilter,
            columnVisibility
        },
        onColumnVisibilityChange: handleColumnVisibilityChange,
        filterFns: {
            fuzzy: fuzzyFilter,
        },
        globalFilterFn: fuzzyFilter,
        enableRowSelection,
        enableMultiRowSelection,
        enableGlobalFilter: enableSearch,
        onRowSelectionChange: (updater) => {
            const newSelection =
                typeof updater === 'function'
                    ? updater(rowSelection)
                    : updater;
            setRowSelection(newSelection);
            onSelectionChange?.(newSelection);
        },
        enableSorting,
        onSortingChange: setSorting,
        onGlobalFilterChange: (value) => {
            setGlobalFilter(String(value));
            onSearchChange?.(String(value));
        },
        initialState: {
            pagination: {
                pageSize,
            },
        },
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        getPaginationRowModel: getPaginationRowModel(),
    });

    const gridTemplateColumns = useMemo(() => {
        const visibleColumns = tableColumns.filter(col => {
            const id = 'id' in col ? col.id : col.accessorKey;
            return columnVisibility[id as string] !== false;
        });
        return visibleColumns
            .map((column) => {
                const size = column.meta?.size || 1;
                if (column.meta?.size === 'icon') {
                    return '2.5rem';
                }
                return `${size}fr`;
            })
            .join(' ') + ((!!renderExpandedContent || !!renderRowButton) ? ' 4rem' : '');
    }, [tableColumns, columnVisibility]);

    const combinedUtilityHeader = useMemo(() => ({
        left: (
            <>
                {enableSearch && (
                    <TableSearch
                        table={table}
                        placeholder={searchPlaceholder}
                    />
                )}
                {typeof utilityHeader === 'object' && utilityHeader?.left}
            </>
        ),
        right: (
            <>
                {!!exportFileName && (
                    <TableExport
                        table={table}
                        fileName={exportFileName}
                        rowSelection={selectedRows || rowSelection}
                    />
                )}
                <ColumnToggle table={table} tableColumns={tableColumns} columnVisibility={columnVisibility}/>
                {typeof utilityHeader === 'object' && utilityHeader?.right}
            </>
        ),
    }), [enableSearch, table, searchPlaceholder, utilityHeader, exportFileName, selectedRows, rowSelection, tableColumns, columnVisibility]);

    return (
        <div className={`gs-table ${styles.tableContainer}`} data-is-mobile={isMobile}>
            {!!utilityHeader && (
                <TableUtilityHeader {...combinedUtilityHeader} />
            )}
            <div className={`${styles.table} ${isMobile ? styles.tableMobile : ''}`} 
                 style={{gridTemplateColumns: isMobile ? undefined : gridTemplateColumns}}>
                <TableHeader
                    headerGroups={table.getHeaderGroups()}
                    gridTemplateColumns={isMobile ? undefined : gridTemplateColumns}
                    renderExpandedContent={!!renderExpandedContent || !!renderRowButton}
                    icon={!!icon}
                    iconPadding={iconPadding}
                    isMobile={isMobile}
                />
                {data.length === 0 && empty ? (
                    empty
                ) : (
                    table.getRowModel().rows.map(row => (
                        <TableRow
                            key={row.id}
                            row={row}
                            gridTemplateColumns={isMobile ? undefined : gridTemplateColumns}
                            icon={icon}
                            renderExpandedContent={renderExpandedContent}
                            renderRowButton={renderRowButton}
                            rowStyle={rowStyle}
                            isMobile={isMobile}
                        />
                    ))
                )}
            </div>
            {enablePagination && data.length > 0 && (
                <TablePagination table={table} setPageSize={handlePageSizeChange}/>
            )}
        </div>
    );
}

export default Table;