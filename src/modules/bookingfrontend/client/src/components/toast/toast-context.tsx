'use client';
import React, { createContext, useContext, useState, ReactNode, useRef } from 'react';

// Define types for toast messages
export type ToastType = 'success' | 'error' | 'warning' | 'info';

export interface ToastMessage {
  id: string;
  type: ToastType;
  text: string | React.ReactNode;
  title?: string | React.ReactNode;
  autoHide?: boolean;
  messageId?: string; // Optional custom ID for deduplication
}

// Define context type
interface ToastContextType {
  addToast: (message: Omit<ToastMessage, 'id'>) => void;
  removeToast: (id: string) => void;
  toasts: ToastMessage[];
  // New FAB related methods
  setFabButtonRef: (ref: React.RefObject<HTMLButtonElement>) => void;
  getFabButtonRef: () => React.RefObject<HTMLButtonElement> | null;
  setFabOpen: (isOpen: boolean) => void;
  isFabOpen: boolean;
}

// Create the context
const ToastContext = createContext<ToastContextType | undefined>(undefined);

// Create a hook to use the toast context
export const useToast = () => {
  const context = useContext(ToastContext);
  if (context === undefined) {
    throw new Error('useToast must be used within a ToastProvider');
  }
  return context;
};

// Create the provider component
export const ToastProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [toasts, setToasts] = useState<ToastMessage[]>([]);
  const [fabButtonRef, setFabButtonRefState] = useState<React.RefObject<HTMLButtonElement> | null>(null);
  const [isFabOpen, setIsFabOpen] = useState(false);

  const addToast = (message: Omit<ToastMessage, 'id'>) => {
    // Check for duplicate messages if messageId is provided
    if (message.messageId) {
      // If a toast with the same messageId exists, don't add another one
      const hasDuplicate = toasts.some(toast => toast.messageId === message.messageId);
      if (hasDuplicate) {
        return; // Skip adding duplicate toast
      }
    }
    
    const id = `toast-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
    const newToast = { ...message, id };
    setToasts(prev => [...prev, newToast]);

    // Auto-hide toast after 5 seconds if autoHide is true
    if (message.autoHide !== false) {
      setTimeout(() => {
        removeToast(id);
      }, 5000);
    }
  };

  const removeToast = (id: string) => {
    setToasts(prev => prev.filter(toast => toast.id !== id));
  };

  const setFabButtonRef = (ref: React.RefObject<HTMLButtonElement>) => {
    setFabButtonRefState(ref);
  };

  const getFabButtonRef = () => {
    return fabButtonRef;
  };

  const setFabOpen = (isOpen: boolean) => {
    setIsFabOpen(isOpen);
  };

  return (
    <ToastContext.Provider value={{ 
      addToast, 
      removeToast, 
      toasts, 
      setFabButtonRef, 
      getFabButtonRef, 
      setFabOpen, 
      isFabOpen 
    }}>
      {children}
    </ToastContext.Provider>
  );
};