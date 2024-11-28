import {FC, useEffect, useRef, useState} from 'react';
import {arrow, autoUpdate, flip, offset, Placement, shift, useFloating} from "@floating-ui/react";
import {useIsMobile} from "@/service/hooks/is-mobile";
import ResourceInfoPopperContent
    from "@/components/building-calendar/modules/resource-filter/resource-info-popper/resource-info-popper-content";
import MobileDialog from "@/components/dialog/mobile-dialog";

interface ResourceInfoPopperProps {
    resource_id: string | null;
    resource_name: string | null;
    onClose: () => void;
    anchor: HTMLElement | null;
    placement: Placement;
}

const ResourceInfoPopper: FC<ResourceInfoPopperProps> = ({ resource_id, onClose, anchor, placement, resource_name }) => {
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(Boolean(resource_id));
    const arrowRef = useRef<HTMLDivElement | null>(null);
    const { x, y, strategy, refs, middlewareData, update } = useFloating({
        open,
        placement,
        middleware: [
            offset(10),
            flip(),
            shift(),
            arrow({ element: arrowRef })
        ],
        whileElementsMounted: autoUpdate
    });

    useEffect(() => {
        setOpen(Boolean(event));
        if (anchor) {
            refs.setReference(anchor);
            update();
        }
    }, [event, anchor, refs, update]);

    useEffect(() => {
        if (open && anchor) {
            const handleClickOutside = (event: MouseEvent) => {
                if (refs.floating.current && !refs.floating.current!.contains(event.target as Node)) {
                    onClose();
                }
            };
            document.addEventListener('mousedown', handleClickOutside);
            return () => {
                document.removeEventListener('mousedown', handleClickOutside);
            };
        }
    }, [open, anchor, onClose, refs.floating]);

    if (!resource_id || !anchor || !resource_name) {
        return null;
    }
    const content = <ResourceInfoPopperContent resource_id={resource_id} onClose={onClose} name={resource_name} />

    if (isMobile) {
        return (
            <MobileDialog open={open} onClose={onClose}>
                {content}
            </MobileDialog>
        );
    }

    const { x: arrowX, y: arrowY } = middlewareData.arrow || {};

    return (
        <>
            {open && (
                <div
                    ref={refs.setFloating}
                    className="eventPopper"
                    style={{
                        position: strategy,
                        top: y ?? 0,
                        left: x ?? 0,
                        zIndex: 100,
                    }}
                >
                    {content}
                    <div
                        ref={arrowRef}
                        className="arrow"
                        style={{
                            position: 'absolute',
                            top: arrowY ?? '',
                            left: arrowX ?? '',
                            [placement.split('-')[0]]: '-4px',
                            width: '8px',
                            height: '8px',
                            background: 'inherit',
                            transform: 'rotate(45deg)',
                        }}
                    />
                </div>
            )}
        </>
    );
}

export default ResourceInfoPopper


