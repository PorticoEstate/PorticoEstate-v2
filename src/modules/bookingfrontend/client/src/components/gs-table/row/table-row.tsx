import { ReactElement, CSSProperties } from 'react';
import { Row, flexRender } from '@tanstack/react-table';
import { ColumnDef } from '../table.types';
import RowExpand from './row-expand';
import styles from '../table.module.scss';

interface TableRowProps<T> {
    row: Row<T>;
    gridTemplateColumns?: string;
    icon?: (data: T) => ReactElement;
    renderExpandedContent?: (data: T) => ReactElement;
    renderRowButton?: (data: T) => ReactElement;
    rowStyle?: (data: T) => CSSProperties | undefined;
    isMobile?: boolean;
}

function TableRow<T>(props: TableRowProps<T>): ReactElement {
    const {
        row,
        gridTemplateColumns,
        icon,
        renderExpandedContent,
        renderRowButton,
        rowStyle,
        isMobile = false
    } = props;

    const hasExtraColumn = renderRowButton || renderExpandedContent;
    
    return (
        <div 
            className={`${styles.tableRowContainer} ${styles.tableRow}`} 
            style={{ 
                display: isMobile ? 'grid' : 'contents',
                gridTemplateColumns: isMobile ? 'repeat(2, 1fr)' : undefined,
                gap: isMobile ? '0.5rem' : undefined,
                ...(rowStyle?.(row.original) || {})
            }}
        >
            {row.getVisibleCells().map(cell => {
                const meta = (cell.column.columnDef as ColumnDef<T>).meta;
                const header = cell.column.columnDef.header;
                const headerContent = typeof header === 'string' ? header : cell.column.id;
                const isSelectionCell = cell.column.id === 'select';
                const isWideCell = meta?.size && typeof meta.size === 'number' && meta.size > 1;

                return (
                    <div 
                        key={cell.id} 
                        className={`${styles.centerCol} ${isWideCell ? styles.bigCol : ''}`}
                        style={{
                            gridColumn: isMobile && isWideCell ? '1 / span 2' : undefined,
                            justifyContent: isSelectionCell ? 'center' : 
                                          meta?.align === 'end' ? 'flex-end' : 
                                          meta?.align === 'center' ? 'center' : 'flex-start'
                        }}
                    >
                        {isMobile && !isSelectionCell && (
                            <div className={styles.columnName}>
                                <span className={styles.capitalize}>{headerContent}</span>
                            </div>
                        )}
                        {flexRender(
                            cell.column.columnDef.cell,
                            cell.getContext()
                        )}
                    </div>
                );
            })}

            {hasExtraColumn && (renderRowButton ? (
                <div 
                    key="action" 
                    className={styles.centerCol}
                    style={{
                        gridColumn: isMobile ? '1 / span 2' : undefined
                    }}
                >
                    {renderRowButton(row.original)}
                </div>
            ) : renderExpandedContent ? (
                <div 
                    key="expand" 
                    style={{
                        gridColumn: isMobile ? '1 / span 2' : undefined
                    }}
                >
                    <RowExpand>
                        {renderExpandedContent(row.original)}
                    </RowExpand>
                </div>
            ) : null)}
        </div>
    );
}

export default TableRow;