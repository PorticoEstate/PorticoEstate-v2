'use client';
import React, { createContext, useContext, useState, ReactNode, useRef } from 'react';

// Define context type
interface ShoppingCartDrawerContextType {
  isOpen: boolean;
  setIsOpen: (isOpen: boolean) => void;
  anchorRef: React.RefObject<HTMLButtonElement> | null;
  setAnchorRef: (ref: React.RefObject<HTMLButtonElement> | null) => void;
}

// Create the context with default values
const ShoppingCartDrawerContext = createContext<ShoppingCartDrawerContextType>({
  isOpen: false,
  setIsOpen: () => {},
  anchorRef: null,
  setAnchorRef: () => {},
});

// Create a hook to use the context
export const useShoppingCartDrawer = () => {
  const context = useContext(ShoppingCartDrawerContext);
  if (context === undefined) {
    throw new Error('useShoppingCartDrawer must be used within a ShoppingCartDrawerProvider');
  }
  return context;
};

// Create the provider component
export const ShoppingCartDrawerProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [isOpen, setIsOpen] = useState<boolean>(false);
  const [anchorRef, setAnchorRefState] = useState<React.RefObject<HTMLButtonElement> | null>(null);

  const setAnchorRef = (ref: React.RefObject<HTMLButtonElement> | null) => {
    setAnchorRefState(ref);
  };

  return (
    <ShoppingCartDrawerContext.Provider value={{ 
      isOpen, 
      setIsOpen, 
      anchorRef, 
      setAnchorRef 
    }}>
      {children}
    </ShoppingCartDrawerContext.Provider>
  );
};

export default ShoppingCartDrawerProvider;