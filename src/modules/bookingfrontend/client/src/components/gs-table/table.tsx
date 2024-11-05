'use client';
import {
    useReactTable,
    getCoreRowModel,
    getSortedRowModel,
    FilterFnOption,
    SortingState, RowSelectionState, FilterFn, getFilteredRowModel, VisibilityState
} from '@tanstack/react-table';
import {useState, useMemo} from 'react';
import styles from './table.module.scss';
import type {ColumnDef, TableProps} from './table.types';
import TableRow from "@/components/gs-table/row/table-row";
import TableHeader from "@/components/gs-table/table-header";
import TableUtilityHeader from "@/components/gs-table/subcomponents/table-utility-header";
import {rankItem} from '@tanstack/match-sorter-utils';
import TableSearch from "@/components/gs-table/subcomponents/table-search";
import ColumnToggle from "@/components/gs-table/subcomponents/column-toggle";



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


function Table<T>({
                      data,
                      columns,
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
                  }: TableProps<T>) {
    const [sorting, setSorting] = useState<SortingState>(defaultSort);
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const [globalFilter, setGlobalFilter] = useState('');
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        defaultColumnVisibility || {}
    );

    // Add selection column if enabled
    const tableColumns = useMemo(() => {
        if (!enableRowSelection) return columns;

        const selectionColumn: ColumnDef<T> = {
            id: 'select',
            meta: { size: 'icon', smallHideTitle: true },
            header: ({ table }) => (
                enableMultiRowSelection ? (
                    <input
                        type="checkbox"
                        checked={table.getIsAllRowsSelected()}
                        // indeterminate={table.getIsSomeRowsSelected()}
                        onChange={table.getToggleAllRowsSelectedHandler()}
                    />
                ) : null
            ),
            cell: ({ row }) => (
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
        onColumnVisibilityChange: (updater) => {
            const newState =
                typeof updater === 'function'
                    ? updater(columnVisibility)
                    : updater;
            setColumnVisibility(newState);
            onColumnVisibilityChange?.(newState);
        },
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
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
    });

    const gridTemplateColumns = useMemo(() => {
        const visibleColumns = tableColumns.filter(col => {
            const id = 'id' in col ? col.id : col.accessorKey;
            return columnVisibility[id as string] !== false;
        });
        return visibleColumns
            .map((column) => {
                const size = column.meta?.size || 1;
                if(column.meta?.size === 'icon'){
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
                <ColumnToggle table={table} tableColumns={tableColumns} columnVisibility={columnVisibility}/>
                {typeof utilityHeader === 'object' && utilityHeader?.right}
            </>
        ),
    }), [utilityHeader, enableSearch, searchPlaceholder, table, tableColumns, columnVisibility]);

    console.log('gridTemplateColumns: ', gridTemplateColumns)
    return (
        <div className={`gs-table ${styles.tableContainer}`}>
            {!!utilityHeader && (
                <TableUtilityHeader {...combinedUtilityHeader} />
            )}
            <div className={styles.table} style={{ gridTemplateColumns: gridTemplateColumns }}>
                <TableHeader
                    headerGroups={table.getHeaderGroups()}
                    gridTemplateColumns={gridTemplateColumns}
                    renderExpandedContent={!!renderExpandedContent || !!renderRowButton}
                    icon={!!icon}
                    iconPadding={iconPadding}
                />
                {data.length === 0 && empty ? (
                    empty
                ) : (
                    table.getRowModel().rows.map(row => (
                        <TableRow
                            key={row.id}
                            row={row}
                            gridTemplateColumns={gridTemplateColumns}
                            icon={icon}
                            renderExpandedContent={renderExpandedContent}
                            renderRowButton={renderRowButton}
                            rowStyle={rowStyle}
                        />
                    ))
                )}
            </div>
        </div>
    );
}

export default Table;