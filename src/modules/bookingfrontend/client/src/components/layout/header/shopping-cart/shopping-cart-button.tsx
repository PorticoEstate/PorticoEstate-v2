'use client'
import React, { FC, useRef, useEffect } from 'react';
import { usePartialApplications } from "@/service/hooks/api-hooks";
import { Badge, Button } from "@digdir/designsystemet-react";
import { ShoppingBasketIcon } from "@navikt/aksel-icons";
import { useShoppingCartDrawer } from './shopping-cart-drawer-context';

interface ShoppingCartButtonProps {
}

const ShoppingCartButton: FC<ShoppingCartButtonProps> = (props) => {
    const { data: cartItems } = usePartialApplications();
    const { setIsOpen, setAnchorRef } = useShoppingCartDrawer();
    const popperAnchorEl = useRef<HTMLButtonElement | null>(null);

    // Register the button ref with the context
    useEffect(() => {
        if (popperAnchorEl.current) {
            setAnchorRef(popperAnchorEl);
        }
    }, [setAnchorRef]);

    return (
        <Button variant={'tertiary'} ref={popperAnchorEl} onClick={() => setIsOpen(true)}>
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
    );
}

export default ShoppingCartButton


