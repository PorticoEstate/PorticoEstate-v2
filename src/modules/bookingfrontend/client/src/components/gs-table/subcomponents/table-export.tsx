// subcomponents/table-export.tsx
import {useMemo} from 'react';
import {Table, CellContext, RowSelectionState} from '@tanstack/react-table';
import {Button} from '@digdir/designsystemet-react';
import {Badge} from '@digdir/designsystemet-react';
import {ColumnMeta} from "@/components/gs-table/table.types";
import {DownloadIcon} from "@navikt/aksel-icons";

interface TableExportProps<T> {
    table: Table<T>;
    fileName?: string;
    rowSelection: RowSelectionState;
}

function convertToCSV<T>(
    data: T[],
    columns: { id: string; header: string }[],
    getValue: (row: T, columnId: string) => any
): string {
    const headers = columns.map(column => {
        const header = typeof column.header === 'string'
            ? column.header
            : column.id;
        return header.includes(',')
            ? `"${header.replace(/"/g, '""')}"`
            : header;
    }).join(',');

    const rows = data.map(row => {
        return columns.map(column => {
            const value = getValue(row, column.id);
            const stringValue = value?.toString() || '';
            return stringValue.includes(',')
                ? `"${stringValue.replace(/"/g, '""')}"`
                : stringValue;
        }).join(',');
    }).join('\n');

    return `${headers}\n${rows}`;
}

function TableExport<T>({table, fileName = 'exported-data', rowSelection}: TableExportProps<T>) {
    // Get selected count from rowSelection prop instead of table
    const selectedCount = useMemo(() => Object.keys(rowSelection).length, [rowSelection]);
    const exportTooltip = useMemo(() => selectedCount > 0
        ? `Export ${selectedCount} selected row${selectedCount === 1 ? '' : 's'}`
        : 'Export all visible rows', [selectedCount])
    const handleExport = () => {
        const exportColumns = table.getAllLeafColumns()
            .filter(column => column.getIsVisible() && column.id !== 'select')
            .map(column => ({
                id: column.id,
                header: typeof column.columnDef.header === 'string'
                    ? column.columnDef.header
                    : column.id
            }));

        // Use rowSelection to determine which rows to export
        const hasSelectedRows = selectedCount > 0;
        const exportData = hasSelectedRows
            ? table.getFilteredRowModel().rows
                .filter(row => rowSelection[row.id])
                .map(row => row.original)
            : table.getFilteredRowModel().rows.map(row => row.original);

        const finalFileName = hasSelectedRows
            ? `${fileName}-selected`
            : fileName;

        const csv = convertToCSV(
            exportData,
            exportColumns,
            (row, columnId) => {
                const column = table.getColumn(columnId);
                if (!column) return '';

                const value = column.accessorFn
                    ? column.accessorFn(row, 0)
                    : (row as any)[columnId];

                // Check for toString in column meta
                const meta = column.columnDef.meta as ColumnMeta | undefined;
                if (meta && 'toStringEx' in meta && typeof meta.toStringEx === 'function') {
                    return meta.toStringEx(value);
                }

                const cellDef = column.columnDef.cell;
                if (cellDef) {
                    if (typeof cellDef === 'function') {
                        try {
                            const context = {
                                getValue: () => value,
                                renderValue: () => value,
                                row: {original: row},
                                column,
                                table,
                            } as CellContext<T, unknown>;

                            const rendered = cellDef(context);
                            if (typeof rendered === 'string' || typeof rendered === 'number') {
                                return rendered;
                            }
                        } catch (error) {
                            console.warn('Error rendering cell value for export:', error);
                        }
                    } else if (typeof cellDef === 'string' || typeof cellDef === 'number') {
                        return cellDef;
                    }
                }

                return value;
            }
        );

        const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
        const link = document.createElement('a');
        if (link.download !== undefined) {
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `${finalFileName}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
    };

    return (
        <Button
            variant="tertiary"
            data-size="sm"
            color={'neutral'}
            onClick={handleExport}
            title={exportTooltip}
        >
            <Badge.Position placement="top-right">
                <Badge
                    color="info"
                    data-size="sm"
                    count={selectedCount || undefined}
                />
				<DownloadIcon fontSize="1.25rem" />

            </Badge.Position>
        </Button>
    );
}

export default TableExport;