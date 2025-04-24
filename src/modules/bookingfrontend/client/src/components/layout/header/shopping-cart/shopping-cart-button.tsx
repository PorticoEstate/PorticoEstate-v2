'use client'
import {FC, useRef, useState} from 'react';
import {usePartialApplications} from "@/service/hooks/api-hooks";
import {Badge, Button} from "@digdir/designsystemet-react";
import { ShoppingBasketIcon } from "@navikt/aksel-icons";
import ShoppingCartPopper from "@/components/layout/header/shopping-cart/shopping-cart-popper";

interface ShoppingCartButtonProps {
}

const ShoppingCartButton: FC<ShoppingCartButtonProps> = (props) => {
    const {data: cartItems} = usePartialApplications();
    const [open, setOpen] = useState<boolean>(false);
    const popperAnchorEl = useRef<HTMLButtonElement | null>(null);


    return (
        <> <Button variant={'tertiary'} ref={popperAnchorEl} onClick={() => setOpen(true)}>

            <div
                style={{
                    display: 'flex',
                    gap: 'var(--ds-spacing-6)',
                }}
            >
                <Badge.Position placement="top-right">
                    {(cartItems?.list?.length || 0) > 0 && (<Badge
                        color="info"
                        // placement="top-right"
                        data-size={'sm'}
                        count={cartItems?.list?.length || undefined}
                        // style={{
                        //     right: '10%',
                        //     top: '16%'
                        // }}
                    />)}
                    <ShoppingBasketIcon width="1.875rem" height="1.875rem" />
                </Badge.Position>

            </div>
            Handlekurv
        </Button>
            <ShoppingCartPopper anchor={popperAnchorEl.current} open={open} setOpen={setOpen}/>
        </>
    );
}

export default ShoppingCartButton


