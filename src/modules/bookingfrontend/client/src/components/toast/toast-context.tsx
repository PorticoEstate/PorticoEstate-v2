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
  timeoutId?: NodeJS.Timeout; // Track timeout for pause/resume
  remainingTime?: number; // Track remaining time when paused
  startTime?: number; // Track when timer started
}

// Define context type
interface ToastContextType {
  addToast: (message: Omit<ToastMessage, 'id'>) => void;
  removeToast: (id: string) => void;
  toasts: ToastMessage[];
  pauseToast: (id: string) => void;
  resumeToast: (id: string) => void;
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
    const startTime = Date.now();
    
    // Auto-hide toast after 10 seconds if autoHide is true (increased from 5 seconds)
    if (message.autoHide !== false) {
      const timeoutId = setTimeout(() => {
        removeToast(id);
      }, 10000);
      
      const newToast = { 
        ...message, 
        id, 
        timeoutId,
        remainingTime: 10000,
        startTime
      };
      setToasts(prev => [...prev, newToast]);
    } else {
      const newToast = { ...message, id };
      setToasts(prev => [...prev, newToast]);
    }
  };

  const removeToast = (id: string) => {
    setToasts(prev => {
      const toastToRemove = prev.find(toast => toast.id === id);
      if (toastToRemove && toastToRemove.timeoutId) {
        clearTimeout(toastToRemove.timeoutId);
      }
      return prev.filter(toast => toast.id !== id);
    });
  };

  const pauseToast = (id: string) => {
    setToasts(prev => prev.map(toast => {
      if (toast.id === id && toast.timeoutId) {
        clearTimeout(toast.timeoutId);
        const elapsed = Date.now() - (toast.startTime || Date.now());
        const remainingTime = Math.max(0, (toast.remainingTime || 10000) - elapsed);
        return {
          ...toast,
          timeoutId: undefined,
          remainingTime,
          startTime: undefined
        };
      }
      return toast;
    }));
  };

  const resumeToast = (id: string) => {
    setToasts(prev => prev.map(toast => {
      if (toast.id === id && !toast.timeoutId && toast.remainingTime !== undefined) {
        const timeoutId = setTimeout(() => {
          removeToast(id);
        }, toast.remainingTime);
        return {
          ...toast,
          timeoutId,
          startTime: Date.now()
        };
      }
      return toast;
    }));
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
      pauseToast,
      resumeToast,
      setFabButtonRef, 
      getFabButtonRef, 
      setFabOpen, 
      isFabOpen 
    }}>
      {children}
    </ToastContext.Provider>
  );
};