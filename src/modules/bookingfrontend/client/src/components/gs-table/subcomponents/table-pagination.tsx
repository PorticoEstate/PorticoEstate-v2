// table-pagination.tsx
import { Table } from '@tanstack/react-table';
import styles from './table-pagination.module.scss';
import { Button, Select } from '@digdir/designsystemet-react';
import { 
    ChevronLeftIcon, 
    ChevronRightIcon, 
    ChevronLeftDoubleIcon, 
    ChevronRightDoubleIcon 
} from '@navikt/aksel-icons';
import { useCallback } from 'react';

interface TablePaginationProps<T> {
    table: Table<T>;
    setPageSize: (pageSize: number) => void;
}

function TablePagination<T>({ table, setPageSize }: TablePaginationProps<T>) {
    const {
        getState,
        setPageIndex,
        getCanPreviousPage,
        getCanNextPage,
        getPageCount,
    } = table;

    const { pageIndex, pageSize } = getState().pagination;

    const scrollToTop = useCallback(() => {
        const tableElement = document.querySelector('.gs-table');
        if (tableElement) {
            tableElement.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }, []);

    const handlePageChange = useCallback((newIndex: number) => {
        setPageIndex(newIndex);
        scrollToTop();
    }, [setPageIndex, scrollToTop]);

    return (
        <div className={styles.pagination}>
            <div className={styles.pageInfo}>
                <span>Rows per page:</span>
                <Select
                    data-size="sm"
                    value={pageSize.toString()}
                    onChange={(e) => {
                        setPageSize(Number(e.target.value));
                        scrollToTop();
                    }}
                >
                    {[10, 20, 30, 40, 50].map((size) => (
                        <option key={size} value={size}>
                            {size}
                        </option>
                    ))}
                </Select>
                <span>
                    Page {pageIndex + 1} of {getPageCount()}
                </span>
            </div>
            <div className={styles.controls}>
                <Button
                    variant="tertiary"
                    data-size="sm"
                    onClick={() => handlePageChange(0)}
                    disabled={!getCanPreviousPage()}
                >
                    <ChevronLeftDoubleIcon fontSize="1.25rem" />
                </Button>
                <Button
                    variant="tertiary"
                    data-size="sm"
                    onClick={() => handlePageChange(pageIndex - 1)}
                    disabled={!getCanPreviousPage()}
                >
                    <ChevronLeftIcon fontSize="1.25rem" />
                </Button>
                <Button
                    variant="tertiary"
                    data-size="sm"
                    onClick={() => handlePageChange(pageIndex + 1)}
                    disabled={!getCanNextPage()}
                >
                    <ChevronRightIcon fontSize="1.25rem" />
                </Button>
                <Button
                    variant="tertiary"
                    data-size="sm"
                    onClick={() => handlePageChange(getPageCount() - 1)}
                    disabled={!getCanNextPage()}
                >
                    <ChevronRightDoubleIcon fontSize="1.25rem" />
                </Button>
            </div>
        </div>
    );
}

export default TablePagination;