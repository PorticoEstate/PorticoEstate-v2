'use client';
import {
	useReactTable,
	getCoreRowModel,
	getSortedRowModel,
	FilterFnOption,
	SortingState, RowSelectionState, FilterFn, getFilteredRowModel, VisibilityState,
    getPaginationRowModel, ColumnFiltersState
} from '@tanstack/react-table';
import {useState, useMemo, useEffect, useRef, useCallback} from 'react';
import styles from './table.module.scss';
import type {ColumnDef, TableProps, TableStorageSettings} from './table.types';
import TableRow from "@/components/gs-table/row/table-row";
import TableHeader from "@/components/gs-table/table-header";
import TableUtilityHeader from "@/components/gs-table/subcomponents/table-utility-header";
import {rankItem} from '@tanstack/match-sorter-utils';
import TableSearch from "@/components/gs-table/subcomponents/table-search";
import ColumnToggle from "@/components/gs-table/subcomponents/column-toggle";
import ColumnFilters from "@/components/gs-table/subcomponents/column-filters";
import TablePagination from "./subcomponents/table-pagination";
import TableExport from "@/components/gs-table/subcomponents/table-export";
import {useIsMobile} from "@/service/hooks/is-mobile";
import {Spinner} from "@digdir/designsystemet-react";


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

// Column filter function
const columnFilter: FilterFn<any> = (row, columnId, filterValue) => {
    if (!filterValue || filterValue.length === 0) return true;

    const cellValue = row.getValue(columnId);
    if (cellValue === null || cellValue === undefined) return false;

    return filterValue.includes(String(cellValue));
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
					  exportFileName,
					  isLoading = false,
					  disableColumnHiding = false,
                      enableColumnFilters = false,
                      onColumnFiltersChange
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
    const [columnFilters, setColumnFilters] = useState<Record<string, any>>(
        storedSettings?.columnFilters || {}
    );

    const tableRef = useRef<HTMLDivElement>(null);
    const [columnWidths, setColumnWidths] = useState<{ [columnId: string]: number }>({});
    const [hasInitialized, setHasInitialized] = useState(false);
    const [windowWidth, setWindowWidth] = useState(typeof window !== 'undefined' ? window.innerWidth : 0);

	useEffect(() => {
		if (!storedSettings?.columnVisibility) {
			const initialVisibility = columns.reduce((acc, column) => {
                if (column.meta?.defaultHidden) {
                    const columnId = ('id' in column ? column.id : (column as any).accessorKey) as string;
                    acc[columnId] = false;
				}
				return acc;
			}, {} as VisibilityState);
			setColumnVisibility(initialVisibility);
		}
	}, [columns, storedSettings]);


	const handleColumnVisibilityChange = (updater: VisibilityState | ((state: VisibilityState) => VisibilityState)) => {
		const newState = typeof updater === 'function' ? updater(columnVisibility) : updater;
		setColumnVisibility(newState);
		onColumnVisibilityChange?.(newState);
	};

	const handlePageSizeChange = (newSize: number) => {
		setPageSize(newSize);
		table.setPageSize(newSize);
	};

    // Add selection column if enabled and set filter functions
	const tableColumns = useMemo(() => {
        let processedColumns = columns.map(col => {
            // Add filter function for columns that have filter config
            if (col.meta?.filter) {
                return {
                    ...col,
                    filterFn: 'columnFilter' as any
                };
            }
            return col;
        });

        if (!enableRowSelection) return processedColumns;

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

        return [selectionColumn, ...processedColumns];
	}, [columns, enableRowSelection, enableMultiRowSelection]);

    // Note: Column widths are not loaded from storage - they're measured fresh each time

    // Handle window resize
    useEffect(() => {
        const handleResize = () => {
            const newWidth = window.innerWidth;
            if (Math.abs(newWidth - windowWidth) > 50) { // Only reset if significant change
                setWindowWidth(newWidth);
                setColumnWidths({});
                setHasInitialized(false);
            }
        };

        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, [windowWidth]);

    // Measure column widths after first render
    const measureColumnWidths = useCallback(() => {
        if (!tableRef.current || hasInitialized || isMobile) return;

        const headerCells = tableRef.current.querySelectorAll('[data-column-id]');
        const newWidths: { [columnId: string]: number } = {};

        headerCells.forEach((cell) => {
            const columnId = cell.getAttribute('data-column-id');
            if (columnId) {
                const rect = cell.getBoundingClientRect();
                newWidths[columnId] = rect.width;
            }
        });

        if (Object.keys(newWidths).length > 0) {
            setColumnWidths(newWidths);
            setHasInitialized(true);
        }
    }, [hasInitialized, isMobile]);

    // Measure widths after data loads
    useEffect(() => {
        if (data.length > 0 && !hasInitialized && !isMobile) {
            const timer = setTimeout(measureColumnWidths, 100);
            return () => clearTimeout(timer);
        }
    }, [data, measureColumnWidths, hasInitialized, isMobile]);

    // Reset initialization when columns change
    useEffect(() => {
        const currentColumnIds = tableColumns.map(col => 'id' in col ? col.id : col.accessorKey).join(',');
        if (hasInitialized) {
            setHasInitialized(false);
            setColumnWidths({});
        }
    }, [tableColumns]);

    // Reset initialization when column visibility changes
    useEffect(() => {
        if (hasInitialized) {
            setHasInitialized(false);
            setColumnWidths({});
        }
    }, [columnVisibility]);

    const handleColumnFiltersChange = (newFilters: Record<string, any>) => {
        setColumnFilters(newFilters);
        onColumnFiltersChange?.(newFilters);
    };

    useEffect(() => {
        if (storageId) {
            saveSettings(storageId, {
                columnVisibility,
                pageSize,
                columnFilters,
            });
        }
    }, [columnVisibility, pageSize, storageId, columnFilters]);


    // Convert column filters to tanstack format
    const tanstackColumnFilters: ColumnFiltersState = useMemo(() => {
        return Object.entries(columnFilters).map(([columnId, values]) => ({
            id: columnId,
            value: values
        }));
    }, [columnFilters]);

	const table = useReactTable({
		data,
		columns: tableColumns,
		state: {
			sorting,
			rowSelection: selectedRows || rowSelection,
			globalFilter,
            columnVisibility,
            columnFilters: tanstackColumnFilters,
		},
		onColumnVisibilityChange: handleColumnVisibilityChange,
        onColumnFiltersChange: (updater) => {
            const newState = typeof updater === 'function' ? updater(tanstackColumnFilters) : updater;
            const newFilters = newState.reduce((acc: Record<string, any>, filter) => {
                acc[filter.id] = filter.value;
                return acc;
            }, {});
            handleColumnFiltersChange(newFilters);
        },
		filterFns: {
			fuzzy: fuzzyFilter,
            columnFilter: columnFilter,
		},
		globalFilterFn: fuzzyFilter,
		enableRowSelection,
		enableMultiRowSelection,
		enableGlobalFilter: enableSearch,
        enableColumnFilters,
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

        // Use stored pixel widths if available and initialized
        if (hasInitialized && Object.keys(columnWidths).length > 0 && !isMobile) {
            const widthValues = visibleColumns.map((column) => {
                const id = 'id' in column ? column.id : column.accessorKey;
                const storedWidth = columnWidths[id as string];

                if (storedWidth) {
                    return `${storedWidth}px`;
                }

                // Fallback to original sizing
                const size = column.meta?.size || 1;
                if (column.meta?.size === 'icon') {
                    return '2.5rem';
                }
                return `${size}fr`;
            });

            return widthValues.join(' ') + ((!!renderExpandedContent || !!renderRowButton) ? ' 4rem' : '');
        }

        // Default fractional sizing
		return visibleColumns
			.map((column) => {
				const size = column.meta?.size || 1;
				if (column.meta?.size === 'icon') {
					return '2.5rem';
				}
				return `${size}fr`;
			})
			.join(' ') + ((!!renderExpandedContent || !!renderRowButton) ? ' 4rem' : '');
    }, [tableColumns, columnVisibility, hasInitialized, columnWidths, isMobile, renderExpandedContent, renderRowButton]);

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
				{typeof utilityHeader === 'object' && utilityHeader?.right}
				{!!exportFileName && (
					<TableExport
						table={table}
						fileName={exportFileName}
						rowSelection={selectedRows || rowSelection}
					/>
				)}
				{enableColumnFilters && (
					<ColumnFilters
						columns={tableColumns}
						data={data}
						filters={columnFilters}
						onFiltersChange={handleColumnFiltersChange}
					/>
				)}
				{!disableColumnHiding && (
					<ColumnToggle table={table} tableColumns={tableColumns} columnVisibility={columnVisibility}/>
				)}
			</>
		),
    }), [enableSearch, table, searchPlaceholder, utilityHeader, exportFileName, selectedRows, rowSelection, tableColumns, columnVisibility, enableColumnFilters, columnFilters, handleColumnFiltersChange, data]);

	return (
        <div className={`gs-table ${styles.tableContainer}`} data-is-mobile={isMobile} ref={tableRef}>
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
				{isLoading ? (
					<div className={styles.loadingContainer}>
						<Spinner data-size={'sm'} aria-label={'loading...'}/>
					</div>
				) : data.length === 0 && empty ? (
					empty
				) : (
					(enablePagination ? table.getRowModel() : table.getFilteredRowModel()).rows.map(row => (
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