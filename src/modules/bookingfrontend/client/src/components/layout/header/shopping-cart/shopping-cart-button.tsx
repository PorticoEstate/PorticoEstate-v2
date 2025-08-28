'use client'
import React, { FC, useRef, useEffect } from 'react';
import { usePartialApplications } from "@/service/hooks/api-hooks";
import { Badge, Button } from "@digdir/designsystemet-react";
import { ShoppingBasketIcon } from "@navikt/aksel-icons";
import ShoppingCartPopper from "@/components/layout/header/shopping-cart/shopping-cart-popper";
import { useIsMobile } from "@/service/hooks/is-mobile";
import { useShoppingCartDrawer } from './shopping-cart-drawer-context';

interface ShoppingCartButtonProps {
}

const ShoppingCartButton: FC<ShoppingCartButtonProps> = (props) => {
    const { data: cartItems } = usePartialApplications();
    const { isOpen, setIsOpen, setAnchorRef } = useShoppingCartDrawer();
    const popperAnchorEl = useRef<HTMLButtonElement | null>(null);
    const isMobile = useIsMobile();

    // Local state for mobile popper
    const [mobilePopperOpen, setMobilePopperOpen] = React.useState<boolean>(false);

    // Register the button ref with the context
    useEffect(() => {
        if (popperAnchorEl.current) {
            setAnchorRef(popperAnchorEl);
        }
    }, [setAnchorRef]);

    const handleClick = () => {
        if (isMobile) {
            setMobilePopperOpen(true);
        } else {
            setIsOpen(true);
        }
    };

    return (
        <>
            {( !isMobile) && (
                <Button variant={'tertiary'} ref={popperAnchorEl} onClick={handleClick}>
                    <div
                        style={{
                            display: 'flex',
                            gap: 'var(--ds-spacing-6)',
                        }}
                    >
                        <Badge.Position placement="top-right">
                            {(cartItems?.list?.length || 0) > 0 && (<Badge
                                color="info"
                                data-size={'sm'}
                                count={cartItems?.list?.length || undefined}
                            />)}
                            <ShoppingBasketIcon width="1.875rem" height="1.875rem" />
                        </Badge.Position>
                    </div>
                    Handlekurv
                </Button>
            )}

            {isMobile && (
                <ShoppingCartPopper
                    anchor={popperAnchorEl.current}
                    open={mobilePopperOpen}
                    setOpen={setMobilePopperOpen}
                />
            )}
        </>
    );
}

export default ShoppingCartButton


