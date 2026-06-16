'use client';
import React, { ReactNode } from 'react';
import { ShoppingCartDrawerProvider } from './shopping-cart-drawer-context';
import ShoppingCartDrawerComponent from './shopping-cart-drawer-component';
interface ShoppingCartProviderProps {
  children: ReactNode;
}

const ShoppingCartProvider: React.FC<ShoppingCartProviderProps> = ({ children }) => {
  return (
    <ShoppingCartDrawerProvider>
      {children}
      <ShoppingCartDrawerComponent />
    </ShoppingCartDrawerProvider>
  );
};

export default ShoppingCartProvider;