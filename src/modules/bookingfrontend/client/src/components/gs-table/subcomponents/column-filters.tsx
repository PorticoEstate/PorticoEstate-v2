import React, {useState, useMemo, useEffect, useRef, useCallback} from 'react';
import {Button, Badge, Select, Field, Label} from '@digdir/designsystemet-react';
import {ColumnDef} from '../table.types';
import styles from './column-filters.module.scss';
import {useIsMobile} from "@/service/hooks/is-mobile";
import Dialog from "@/components/dialog/mobile-dialog";
import {FilterIcon} from "@navikt/aksel-icons";

interface ColumnFiltersProps<T> {
    columns: ColumnDef<T>[];
    data: T[];
    filters: Record<string, any>;
    onFiltersChange: (filters: Record<string, any>) => void;
}

function ColumnFilters<T>({columns, data, filters, onFiltersChange}: ColumnFiltersProps<T>) {
    const [isOpen, setIsOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);
    const isMobile = useIsMobile();

    // Handle clicking outside to close
    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }

        if (isOpen) {
            document.addEventListener('mousedown', handleClickOutside);
            return () => document.removeEventListener('mousedown', handleClickOutside);
        }
    }, [isOpen]);

    // Get filterable columns
    const filterableColumns = useMemo(() => {
        return columns.filter(col => col.meta?.filter);
    }, [columns]);

    // Generate filter options for each column
    const filterOptions = useMemo(() => {
        const options: Record<string, Array<{label: string; value: string}>> = {};

        filterableColumns.forEach(column => {
            const columnId = ('id' in column ? column.id : (column as any).accessorKey) as string;
            const filterConfig = column.meta?.filter;

            if (filterConfig?.options) {
                options[columnId] = filterConfig.options;
            } else if (filterConfig?.getUniqueValues) {
                options[columnId] = filterConfig.getUniqueValues(data);
            } else {
                // Auto-generate unique values
                const uniqueValues = new Set<string>();
                data.forEach(row => {
                    const value = (column as any).accessorFn ? (column as any).accessorFn(row, 0) : (row as any)[columnId];
                    if (value !== null && value !== undefined) {
                        uniqueValues.add(String(value));
                    }
                });

                options[columnId] = Array.from(uniqueValues).map(value => ({
                    label: value,
                    value: value
                }));
            }
        });

        return options;
    }, [filterableColumns, data]);

    const handleFilterChange = (columnId: string, selectedValue: string) => {
        const updatedFilters = {
            ...filters,
            [columnId]: selectedValue ? [selectedValue] : undefined
        };

        // Remove undefined filters
        Object.keys(updatedFilters).forEach(key => {
            if (updatedFilters[key] === undefined) {
                delete updatedFilters[key];
            }
        });

        onFiltersChange(updatedFilters);
    };

    const clearAllFilters = () => {
        onFiltersChange({});
    };

    const handleClose = () => {
        setIsOpen(false);
    };

    const hasActiveFilters = Object.keys(filters).length > 0;
    const activeFilterCount = Object.values(filters).reduce((count, filterValues) => {
        return count + (Array.isArray(filterValues) ? filterValues.length : 0);
    }, 0);

    // Render filter options UI
    const renderFilterOptions = () => (
        <>
            <div className={styles.header}>
                <h3>Filtrer kolonner</h3>
                {hasActiveFilters && (
                    <button
                        className={styles.resetButton}
                        onClick={clearAllFilters}
                    >
                        Fjern alle
                    </button>
                )}
            </div>

            <div className={styles.filterContent}>
                {filterableColumns.map(column => {
                    const columnId = ('id' in column ? column.id : (column as any).accessorKey) as string;
                    const columnHeader = typeof (column as any).header === 'string' ? (column as any).header : columnId;
                    const options = filterOptions[columnId] || [];
                    const activeFilters = filters[columnId] || [];

                    return (
                        <div key={columnId} className={styles.filterGroup}>
                            <Field>
                                <Label className={styles.filterGroupLabel}>{columnHeader}</Label>
                                <Select
                                    value={activeFilters.length > 0 ? activeFilters[0] : ''}
                                    onChange={(e) => handleFilterChange(columnId, e.target.value)}
                                >
                                    <Select.Option value="">Alle</Select.Option>
                                    {options.map((option: {label: string; value: string}) => (
                                        <Select.Option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </Select.Option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    );
                })}
            </div>
        </>
    );

    if (filterableColumns.length === 0) {
        return null;
    }

    return (
        <div className={styles.columnFilters} ref={menuRef}>
            <Button
                variant="tertiary"
                data-size={'sm'}
                color={'neutral'}
                onClick={() => setIsOpen(!isOpen)}
                title="Filter columns"
            >
                <Badge.Position placement="top-right">
                    {activeFilterCount > 0 && (
                        <Badge
                            color="info"
                            data-size={'sm'}
                            count={activeFilterCount || undefined}
                        />
                    )}
					<FilterIcon fontSize="1.25rem"/>
                </Badge.Position>
            </Button>

            {/* Mobile Dialog */}
            {isMobile ? (
                <Dialog
                    open={isOpen}
                    onClose={handleClose}
                    title="Column Filters"
                    showDefaultHeader={true}
                    closeOnBackdropClick={true}
					dialogId={'table-column-filters-dialog'}
                >
                    <div className={styles.mobileDialogContent}>
                        {renderFilterOptions()}
                    </div>
                </Dialog>
            ) : (
                /* Desktop Dropdown */
                isOpen && (
                    <div className={styles.menu}>
                        {renderFilterOptions()}
                    </div>
                )
            )}
        </div>
    );
}

export default ColumnFilters;