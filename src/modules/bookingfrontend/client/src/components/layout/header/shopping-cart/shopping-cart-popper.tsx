import React, {Dispatch, FC, useEffect, useRef, useState} from 'react';
import {arrow, autoUpdate, flip, offset, shift, useFloating} from "@floating-ui/react";
import {useIsMobile} from "@/service/hooks/is-mobile";
import ShoppingCartContent from "@/components/layout/header/shopping-cart/shopping-cart-content";
import MobileDialog from "@/components/dialog/mobile-dialog";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";

interface ShoppingCartPopperProps {
    anchor: HTMLButtonElement | null;
    open: boolean;
    setOpen: Dispatch<boolean>
}

const placement = 'bottom-end';
const ShoppingCartPopper: FC<ShoppingCartPopperProps> = (props) => {
    const [currentApplication, setCurrentApplication] = useState<{
        application_id: number,
        date_id: number,
        building_id: number
    }>();
    const arrowRef = useRef<HTMLDivElement | null>(null);
    const isMobile = useIsMobile();


    const {x, y, strategy, refs, middlewareData, update} = useFloating({
        open: props.open,
        placement: placement,
        middleware: [
            offset(10),
            flip(),
            shift(),
            arrow({element: arrowRef})
        ],
        whileElementsMounted: autoUpdate
    });

    useEffect(() => {
        if (props.anchor) {
            refs.setReference(props.anchor);
            update();
        }
    }, [props.anchor, refs, update]);

    useEffect(() => {
        if (props.open && props.anchor) {
            const handleClickOutside = (event: MouseEvent) => {
                if (refs.floating.current && !refs.floating.current!.contains(event.target as Node)) {
                    props.setOpen(false);
                }
            };
            document.addEventListener('mousedown', handleClickOutside);
            return () => {
                document.removeEventListener('mousedown', handleClickOutside);
            };
        }
    }, [props.open, props.anchor, props.setOpen, refs.floating]);


    const {x: arrowX, y: arrowY} = middlewareData.arrow || {};

    const content = <ShoppingCartContent setOpen={props.setOpen} setCurrentApplication={setCurrentApplication}/>


    if (isMobile) {
        return (
            <>
                <MobileDialog open={props.open} onClose={() => props.setOpen(false)}>
                    {content}
                </MobileDialog>
                {currentApplication && (
                    <ApplicationCrud onClose={() => setCurrentApplication(undefined)} applicationId={currentApplication.application_id} date_id={currentApplication.date_id}
                               building_id={currentApplication.building_id} />
                )}
            </>
        );
    }

    return (
        <>
            {props.open && (
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
            {currentApplication && (
                <ApplicationCrud onClose={() => setCurrentApplication(undefined)} applicationId={currentApplication.application_id} date_id={currentApplication.date_id}
                           building_id={currentApplication.building_id} />
            )}
        </>
    );
}

export default ShoppingCartPopper


