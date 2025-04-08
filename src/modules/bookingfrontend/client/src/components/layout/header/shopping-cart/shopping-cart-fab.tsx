'use client'

import {FC, useRef, useState} from 'react';
import {Badge, Button} from "@digdir/designsystemet-react";
import { ShoppingBasketIcon } from "@navikt/aksel-icons";
import {usePartialApplications} from "@/service/hooks/api-hooks";
import styles from './shopping-cart-fab.module.scss';
import ShoppingCartPopper from "@/components/layout/header/shopping-cart/shopping-cart-popper";

interface ShoppingCartFabProps {
}

const ShoppingCartFab: FC<ShoppingCartFabProps> = (props) => {
    const {data: cartItems} = usePartialApplications();
    const [open, setOpen] = useState<boolean>(false);
    const popperAnchorEl = useRef<HTMLButtonElement | null>(null);
    return (
        <>

            <Button variant={'primary'}
                    className={`${styles.fab} ${(cartItems?.list.length ?? 0 > 0) ? '' : styles.hidden}`}
                    ref={popperAnchorEl} onClick={() => setOpen(true)}>

                <ShoppingBasketIcon fontSize="1.25rem" />
                Handlekurv
                <Badge
                    data-color="brand3"
                    data-size={'sm'}
					className={styles.badge}
                    style={{
                        display: 'flex',
                        gap: 'var(--ds-spacing-2)',
                        // backgroundColor: 'var(--ds-color-base-default)'
                    }}
                    count={cartItems?.list.length ?? 0}
                ></Badge>


            </Button>
            <ShoppingCartPopper anchor={popperAnchorEl.current} open={open} setOpen={setOpen}/>
        </>

    );
}

export default ShoppingCartFab


