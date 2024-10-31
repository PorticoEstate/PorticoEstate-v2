import {CSSProperties, ReactElement, ReactNode} from 'react';
import {ColumnDef as TanStackColumnDef, RowSelectionState, SortingState, VisibilityState} from '@tanstack/react-table';
//
// export type TableColumnDefinition<T> = {
//     /*
//      * [K in keyof T]-? infers what K is from the value of field "key", -? extracts everything to top level, so final
//      * type looks like:
//      * TableColumnDefinition<DemoType> = {
//      *     key: keyof DemoType,
//      *     ...,
//      *     render?: (value: value of DemoType[key], source: DemoType) => ReactElement | string;
//      *     ...
//      * }
//      *
//      */
//     [K in keyof T]-?: {
//         key: K;
//         title?: string; // Override key field in header rendering
//         contentAlign?: 'flex-end' | 'center'
//         render?: (value: T[K], source: T) => ReactElement | string; // override default tostring render of column data
//         size?:0.5| 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12; // default 1, if increased, increases the width of the given column
//         hideTitle?: boolean; // hide table header title
//         sortCompare?: (a: T[K], b: T[K], sourceA: T, sourceB: T) => number; // how to compare different rows, also enables sorting
//         smallHideTitle?: boolean; // in mobile small width, hide column title
//     };
// }[keyof T];
//
// export interface TableOptions<T> {
//     columns: (keyof T | TableColumnDefinition<T>)[];
//     iconPadding?: CSSProperties['paddingLeft'];
//     expandedContent?: (source: T) => ReactElement;
//     rowStyle?: (data: T) => CSSProperties | undefined;
//     icon?: (data: T) => ReactElement;
//     keyField: keyof T;
// }


export interface ColumnMeta {
    align?: 'start' | 'center' | 'end';
    size?: 0.5 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12 | 'icon';
    hideHeader?: boolean;
    smallHideTitle?: boolean;
}

// Properly extending TanStack's ColumnDef
export type ColumnDef<T> = TanStackColumnDef<T> & {
    meta?: ColumnMeta;
};

// Table props
export interface TableProps<T> {
    data: T[];
    columns: ColumnDef<T>[];
    empty?: JSX.Element;
    enableSorting?: boolean;
    renderExpandedContent?: (row: T) => ReactElement;
    icon?: (data: T) => ReactElement;
    iconPadding?: CSSProperties['paddingLeft'];
    rowStyle?: (data: T) => CSSProperties | undefined;
    defaultSort?: SortingState;
    // Selection props
    enableRowSelection?: boolean;
    enableMultiRowSelection?: boolean;
    onSelectionChange?: (selection: RowSelectionState) => void;
    selectedRows?: RowSelectionState;
    utilityHeader?: {
        left?: ReactNode;
        right?: ReactNode;
    };
    enableSearch?: boolean;
    searchPlaceholder?: string;
    onSearchChange?: (value: string) => void;
    defaultColumnVisibility?: VisibilityState;
    onColumnVisibilityChange?: (visibility: VisibilityState) => void;
}
