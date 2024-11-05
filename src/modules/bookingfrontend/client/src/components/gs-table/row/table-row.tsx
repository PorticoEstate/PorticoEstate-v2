import {ReactElement, CSSProperties} from 'react';
import {Row, flexRender} from '@tanstack/react-table';
import {ColumnDef} from '../table.types';
import RowExpand from './row-expand';
import styles from '../table.module.scss';

interface TableRowProps<T> {
    row: Row<T>;
    gridTemplateColumns?: string;
    icon?: (data: T) => ReactElement;
    renderExpandedContent?: (data: T) => ReactElement;
    renderRowButton?: (data: T) => ReactElement;
    rowStyle?: (data: T) => CSSProperties | undefined;
}

function TableRow<T>(props: TableRowProps<T>): ReactElement {
    const {
        row,
        gridTemplateColumns,
        icon,
        renderExpandedContent,
        renderRowButton,
        rowStyle
    } = props;

    // Determine if we need the extra column for button/expand
    const hasExtraColumn = renderRowButton || renderExpandedContent;

    return (
        <div
            className={`${styles.tableRowContainer} ${styles.tableRow}`}
            key={row.id}
            style={{
                // gridTemplateAreas: renderExpandedContent
                //     ? `"line action" "content content"`
                //     : `"line action"`,
                // gap: "0 0.5rem",
                // ...(rowStyle?.(row.original) || {})
                display: 'contents'
            }}
        >
            {/*{icon && (*/}
            {/*    <div style={{*/}
            {/*        margin: '0.5rem',*/}
            {/*        marginRight: '16px',*/}
            {/*        marginLeft: '0',*/}
            {/*        display: 'flex',*/}
            {/*        justifyContent: 'center',*/}
            {/*        alignItems: 'center'*/}
            {/*    }}>*/}
            {/*        {icon(row.original)}*/}
            {/*    </div>*/}
            {/*)}*/}

            {/*<div*/}
            {/*    className={styles.tableRow}*/}
            {/*    style={{*/}
            {/*        gridTemplateColumns,*/}
            {/*        gridArea: 'line'*/}
            {/*    }}*/}
            {/*>*/}
            {row.getVisibleCells().map(cell => {
                const meta = (cell.column.columnDef as ColumnDef<T>).meta;
                const header = cell.column.columnDef.header;
                const headerContent = typeof header === 'string'
                    ? header
                    : cell.column.id;

                return (
                    <div
                        key={cell.id}
                        className={`${styles.centerCol} ${
                            meta?.size && typeof meta.size === 'number' && meta.size > 1 ? styles.bigCol : ''
                        }`}
                        style={{
                            justifyContent: meta?.align === 'end'
                                ? 'flex-end'
                                : meta?.align === 'center'
                                    ? 'center'
                                    : 'flex-start',
                        }}
                    >
                        {!meta?.smallHideTitle && (
                            <div className={`heading-s ${styles.columnName}`}>
                                    <span className={styles.capitalize}>
                                        {headerContent}
                                    </span>
                            </div>
                        )}
                        {flexRender(
                            cell.column.columnDef.cell,
                            cell.getContext()
                        )}
                    </div>
                );
            })}
            {/*</div>*/}


            {hasExtraColumn && (renderRowButton ? (
                <div
                    key={'action'}
                    className={`${styles.centerCol}`}
                    style={{
                        justifyContent: 'flex-start',
                    }}
                >
                    {renderRowButton(row.original)}
                </div>
            ) : renderExpandedContent ? (
                <RowExpand>
                    {renderExpandedContent(row.original)}
                </RowExpand>
            ) : null)}


            {/*{renderExpandedContent && (*/}
            {/*    <div className={styles.expandedContent} style={{ gridArea: 'content' }}>*/}
            {/*        {renderExpandedContent(row.original)}*/}
            {/*    </div>*/}
            {/*)}*/}
        </div>
    );
}

export default TableRow;