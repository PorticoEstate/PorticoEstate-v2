import {FC, PropsWithChildren, useState} from 'react';
import {Chevron} from '../vectors/chevron';
import styles from './row-expand.module.scss';
import ExpandableContent from "@/components/gs-table/expandable-content/expandable-content";
import tableStyles from '../table.module.scss';

interface RowExpandProps extends PropsWithChildren {
    unCenter?: boolean;
    renderExpandButton?: (props: {
        isExpanded: boolean;
        onClick: () => void;
    }) => React.ReactNode;
}

const RowExpand: FC<RowExpandProps> = ({children, unCenter, renderExpandButton}) => {
    const [expanded, setExpanded] = useState<boolean>(false);

    const defaultExpandButton = (
        <button
            onClick={() => setExpanded(!expanded)}
            className={styles.expandButton}
        >
            <Chevron open={expanded}/>
        </button>
    );

    return (
        <>
            <div
                key={'action'}
                className={`${tableStyles.centerCol}`}
                style={{
                    justifyContent: 'flex-start',
                }}
            >
                <div className={styles.buttonContainer}>
                    {renderExpandButton ?
                        renderExpandButton({
                            isExpanded: expanded,
                            onClick: () => setExpanded(!expanded)
                        })
                        : defaultExpandButton
                    }
                </div>

            </div>
            <ExpandableContent
                open={expanded}
                className={styles.expandableContent}
                style={{
                    // gridArea: 'content',
                    // display: expanded ? 'block' : 'none'
                }}
            >
                {children}
            </ExpandableContent>
        </>
    );
};

export default RowExpand;