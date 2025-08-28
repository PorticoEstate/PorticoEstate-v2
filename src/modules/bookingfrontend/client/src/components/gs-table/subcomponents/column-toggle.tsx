import {useState, useRef, useEffect, useCallback} from 'react';
import {Table, Column, VisibilityState} from '@tanstack/react-table';
import styles from './column-toggle.module.scss';
import type {ColumnDef} from "@/components/gs-table/table.types";
import {Badge, Button} from "@digdir/designsystemet-react";
import { CogIcon } from "@navikt/aksel-icons";
import {useIsMobile} from "@/service/hooks/is-mobile";
import Dialog from "@/components/dialog/mobile-dialog";

interface ColumnToggleProps<T> {
    table: Table<T>;
    tableColumns: ColumnDef<T>[];
    columnVisibility: VisibilityState;

}

function ColumnToggle<T>({table, tableColumns, columnVisibility}: ColumnToggleProps<T>) {
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

    // Get fresh column data
    const allColumns = table.getAllLeafColumns().filter(column => column.getCanHide())
    const hiddenColumnsCount = Object.values(columnVisibility).filter(v => !v).length;

    // Close both the dropdown and dialog
    const handleClose = () => {
        setIsOpen(false);
    };


    // Memoize column render for performance
    const renderColumn = useCallback((column: Column<T, unknown>) => {
        const columnId = column.id;
        const isVisible = columnVisibility[columnId as string] !== false;
        const title = typeof column.columnDef.header === 'string'
            ? column.columnDef.header
            : columnId;

        return (
            <label
                key={columnId}
                className={styles.columnOption}
            >
                <input
                    type="checkbox"
                    checked={isVisible}
                    onChange={(e) => {
                        column.toggleVisibility(e.target.checked);
                    }}
                />
                <span>{title}</span>
            </label>
        );
    }, [columnVisibility]);

    // Render column toggle UI
    const renderColumnOptions = () => (
        <>
            <div className={styles.header}>
                <h3>Toggle Columns</h3>
                <button
                    className={styles.resetButton}
                    onClick={() => table.resetColumnVisibility()}
                >
                    Reset
                </button>
            </div>
            <div className={styles.columns}>
                {allColumns
                    .filter(column => column.id !== 'select')
                    .map(renderColumn)}
            </div>
        </>
    );

    return (
        <div className={styles.columnToggle} ref={menuRef}>
            <Button variant="tertiary"
                    data-size={'sm'}
                    color={'neutral'}
                    onClick={() => setIsOpen(!isOpen)}
                    title="Toggle columns"
            >
                <Badge.Position placement="top-right">
                    {hiddenColumnsCount > 0 && (<Badge
                        color="info"
                        data-size={'sm'}
                        count={hiddenColumnsCount || undefined}
                    >
                    </Badge>)}
                    <CogIcon fontSize="1.25rem" />
                </Badge.Position>
            </Button>

            {/* Mobile Dialog */}
            {isMobile ? (
                <Dialog
					dialogId={'table-column-toggle-dialog'}
                    open={isOpen}
                    onClose={handleClose}
                    title="Column Visibility"
                    showDefaultHeader={true}
                    closeOnBackdropClick={true}
                >
                    <div className={styles.mobileDialogContent}>
                        {renderColumnOptions()}
                    </div>
                </Dialog>
            ) : (
                /* Desktop Dropdown */
                isOpen && (
                    <div className={styles.menu}>
                        {renderColumnOptions()}
                    </div>
                )
            )}
        </div>
    );
}

export default ColumnToggle;