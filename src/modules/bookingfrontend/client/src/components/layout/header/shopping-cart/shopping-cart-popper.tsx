import React, {Dispatch, FC, useEffect, useRef} from 'react';
import {arrow, autoUpdate, flip, offset, shift, useFloating} from "@floating-ui/react";
import {useIsMobile} from "@/service/hooks/is-mobile";
import ShoppingCartContent from "@/components/layout/header/shopping-cart/shopping-cart-content";
import MobileDialog from "@/components/dialog/mobile-dialog";

interface ShoppingCartPopperProps {
    anchor: HTMLButtonElement | null;
    open: boolean;
    setOpen: Dispatch<boolean>
}

const placement = 'bottom-end';
const ShoppingCartPopper: FC<ShoppingCartPopperProps> = (props) => {
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

    const content = <ShoppingCartContent setOpen={props.setOpen}/>


    if (isMobile) {
        return (
            <MobileDialog open={props.open} onClose={() => props.setOpen(false)}>
                {content}
            </MobileDialog>
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
        </>
    );
}

export default ShoppingCartPopper


