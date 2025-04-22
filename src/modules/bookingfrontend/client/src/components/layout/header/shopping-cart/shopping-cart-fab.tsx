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
    
    // Check if cart has items
    const hasItems = (cartItems?.list.length ?? 0) > 0;
    
    return (
        <div style={{ 
            position: 'fixed', 
            bottom: '20px', 
            right: '20px',
            opacity: hasItems ? 1 : 0,
            visibility: hasItems ? 'visible' : 'hidden',
            transition: 'visibility 0.3s, opacity 0.5s linear',
            zIndex: 99999
        }}>
            <Button variant={'primary'}
                    className={styles.fab}
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
                    }}
                    count={cartItems?.list.length ?? 0}
                ></Badge>
            </Button>
            <ShoppingCartPopper anchor={popperAnchorEl.current} open={open} setOpen={setOpen}/>
        </div>

    );
}

export default ShoppingCartFab


