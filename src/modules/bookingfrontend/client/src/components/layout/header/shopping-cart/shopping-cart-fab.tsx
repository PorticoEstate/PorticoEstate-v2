'use client'

import {FC, useRef, useState} from 'react';
import {Badge, Button, Chip, Tag} from "@digdir/designsystemet-react";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faShoppingBasket} from "@fortawesome/free-solid-svg-icons";
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

                <FontAwesomeIcon size={'lg'} icon={faShoppingBasket}/>
                Handlekurv
                <Badge
                    data-color="neutral"
                    data-size={'sm'}
                    style={{
                        display: 'flex',
                        gap: 'var(--ds-spacing-2)',
                        color: 'var(--ds-color-base-default)'
                    }}
                    count={cartItems?.list.length ?? 0}
                ></Badge>


            </Button>
            <ShoppingCartPopper anchor={popperAnchorEl.current} open={open} setOpen={setOpen}/>
        </>

    );
}

export default ShoppingCartFab


