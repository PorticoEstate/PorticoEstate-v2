'use client';
import React, { ReactNode } from 'react';
import { ShoppingCartDrawerProvider } from './shopping-cart-drawer-context';
import ShoppingCartDrawerComponent from './shopping-cart-drawer-component';
import { useIsMobile } from '@/service/hooks/is-mobile';

interface ShoppingCartProviderProps {
  children: ReactNode;
}

const ShoppingCartProvider: React.FC<ShoppingCartProviderProps> = ({ children }) => {
  const isMobile = useIsMobile();

  return (
    <ShoppingCartDrawerProvider>
      {children}
      {!isMobile && <ShoppingCartDrawerComponent />}
    </ShoppingCartDrawerProvider>
  );
};

export default ShoppingCartProvider;