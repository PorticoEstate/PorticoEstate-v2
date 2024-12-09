import {useState, useRef, useEffect, useCallback} from 'react';
import {Table, Column, VisibilityState} from '@tanstack/react-table';
import styles from './column-toggle.module.scss';
import type {ColumnDef} from "@/components/gs-table/table.types";
import {Badge, Button} from "@digdir/designsystemet-react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faShoppingBasket, faSliders} from "@fortawesome/free-solid-svg-icons";

interface ColumnToggleProps<T> {
    table: Table<T>;
    tableColumns: ColumnDef<T>[];
    columnVisibility: VisibilityState;

}

function ColumnToggle<T>({ table, tableColumns, columnVisibility }: ColumnToggleProps<T>) {
    const [isOpen, setIsOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);


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

    return (
        <div className={styles.columnToggle} ref={menuRef}>
            <Button variant="tertiary"
                    data-size={'sm'}
                    color={'neutral'}
                onClick={() => setIsOpen(!isOpen)}
                // className={styles.toggleButton}
                title="Toggle columns"
            >

                {hiddenColumnsCount > 0 && (<Badge
                    color="info"
                    placement="top-right"
                    data-size={'sm'}
                    count={hiddenColumnsCount || undefined}
                    // style={{
                    //     right: '10%',
                    //     top: '16%'
                    // }}
                >
                    <FontAwesomeIcon size={'lg'} icon={faSliders}/>
                </Badge>) || <FontAwesomeIcon icon={faSliders}/>}


                {/*<Badge count={hiddenColumnsCount} size={'sm'}>*/}
                {/*    <FontAwesomeIcon icon={faSliders} />*/}
                {/*</Badge>*/}
                {/*{hiddenColumnsCount > 0 && (*/}
                {/*    <span className={styles.hiddenCount}>*/}
                {/*        {hiddenColumnsCount}*/}
                {/*    </span>*/}
                {/*)}*/}
            </Button>

            {isOpen && (
                <div className={styles.menu}>
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
                </div>
            )}
        </div>
    );
}

export default ColumnToggle;