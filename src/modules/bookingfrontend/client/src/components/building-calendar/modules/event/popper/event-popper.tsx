import React, { FC, useEffect, useState, useRef } from 'react';
import { useFloating, autoUpdate, offset, flip, shift, arrow, Placement } from '@floating-ui/react';
import { FCallEvent, FCallTempEvent } from "@/components/building-calendar/building-calendar.types";
import TempEventPopperContent from "@/components/building-calendar/modules/event/popper/content/temp-event-popper-content";
import EventPopperContent from "@/components/building-calendar/modules/event/popper/content/event-popper-content";
import MobileDialog from "@/components/dialog/mobile-dialog";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import {useIsMobile} from "@/service/hooks/is-mobile";

interface EventPopperProps {
    event: FCallEvent | FCallTempEvent | null;
    onClose: () => void;
    anchor: HTMLElement | null;
    placement: Placement;
}

const EventPopper: FC<EventPopperProps> = ({ event, onClose, anchor, placement }) => {
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(Boolean(event));
    const t = useTrans();
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

    if (!event || !anchor) {
        return null;
    }

    const content = event.extendedProps.type === 'temporary' ? (
        <TempEventPopperContent event={event as FCallTempEvent} onClose={onClose} />
    ) : (
        <EventPopperContent event={event as FCallEvent} onClose={onClose} />
    );

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
};

export default EventPopper;