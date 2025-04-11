'use client';
import React, { useRef, useEffect } from 'react';
import { useToast, ToastMessage, ToastType } from './toast-context';
import styles from './toast.module.scss';
import { XMarkIcon } from '@navikt/aksel-icons';
import { useTrans } from '@/app/i18n/ClientTranslationProvider';
import {Alert, Button, Badge, Heading, Paragraph} from "@digdir/designsystemet-react";
import { SeverityColors } from "@digdir/designsystemet-react/colors";
import { usePartialApplications } from "@/service/hooks/api-hooks";
import { ShoppingBasketIcon } from "@navikt/aksel-icons";
import ShoppingCartPopper from "@/components/layout/header/shopping-cart/shopping-cart-popper";

const ToastContainer: React.FC = () => {
  const { toasts, removeToast, setFabButtonRef, setFabOpen, isFabOpen } = useToast();
  const { data: cartItems } = usePartialApplications();
  const fabButtonRef = useRef<HTMLButtonElement>(null);
  const t = useTrans();

  // Register the FAB button ref with the context
  useEffect(() => {
    setFabButtonRef(fabButtonRef);
  }, [setFabButtonRef]);

  // Determine if the shopping cart FAB should be shown
  const showShoppingCart = cartItems?.list.length && cartItems.list.length > 0;

  return (
    <div className={styles.floatingContainer}>
      {/* Toast notifications */}
      <div className={styles.toastContainer}>
        {toasts.map((toast) => (
          <Toast key={toast.id} toast={toast} onClose={() => removeToast(toast.id)} />
        ))}
      </div>

      {/* Shopping Cart FAB */}
      {!!showShoppingCart && (
        <div className={styles.fabContainer}>
          <Button
            variant={'primary'}
            className={styles.fab}
            ref={fabButtonRef}
            onClick={() => setFabOpen(true)}
          >
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
          <ShoppingCartPopper anchor={fabButtonRef.current} open={isFabOpen} setOpen={setFabOpen}/>
        </div>
      )}
    </div>
  );
};

interface ToastProps {
  toast: ToastMessage;
  onClose: () => void;
}

const Toast: React.FC<ToastProps> = ({ toast, onClose }) => {
  const t = useTrans();
  const { type, title, text } = toast;

  const mapToastTypeToAlertColor = (toastType: ToastType): SeverityColors => {
    switch (toastType) {
      case 'error':
        return 'danger';
      case 'success':
        return 'success';
      case 'warning':
        return 'warning';
      case 'info':
        return 'info';
      default:
        return 'info';
    }
  };

  const color = mapToastTypeToAlertColor(type);

  return (
    <Alert
      data-color={color}
      className={`${styles.alert} ${styles.alertCompact} ${styles.toastAnimation}`}
      data-size="sm"
    >
      <div>
        {title && <Heading level={5} data-size='2xs' style={{
          marginBottom: 'var(--ds-size-2)'
        }}>
          {title}
        </Heading>}
        <Paragraph data-size={'xs'}>
          {text}
        </Paragraph>
      </div>
      <Button
        icon
        data-color='neutral'
        variant='tertiary'
        data-size="sm"
        aria-label={t('bookingfrontend.close_message')}
        onClick={onClose}
        style={{padding: '4px', marginLeft: '4px'}}
      >
        <XMarkIcon aria-hidden fontSize="1rem"/>
      </Button>
    </Alert>
  );
};

export {ToastContainer};
export default ToastContainer;