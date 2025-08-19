'use client';
import React, { createContext, useContext, useEffect, useRef, ReactNode } from 'react';

interface ScrollLockContextType {
  lockScroll: (lockId: string) => void;
  unlockScroll: (lockId: string) => void;
}

const ScrollLockContext = createContext<ScrollLockContextType | undefined>(undefined);

interface ScrollLockProviderProps {
  children: ReactNode;
}

export const ScrollLockProvider: React.FC<ScrollLockProviderProps> = ({ children }) => {
  const lockCountRef = useRef(new Set<string>());

  const lockScroll = (lockId: string) => {
    const wasAlreadyLocked = lockCountRef.current.has(lockId);
    if (wasAlreadyLocked) {
      console.log(`⚠️ Attempted duplicate lock for: ${lockId} (ignored)`);
      return;
    }
    console.log(`🔒 Locking scroll for: ${lockId}. Active locks:`, Array.from(lockCountRef.current));
    lockCountRef.current.add(lockId);
    updateBodyClass();
    console.log(`🔒 After lock - Active locks:`, Array.from(lockCountRef.current));
  };

  const unlockScroll = (lockId: string) => {
    const wasLocked = lockCountRef.current.has(lockId);
    if (!wasLocked) {
      console.log(`⚠️ Attempted unlock for non-existent lock: ${lockId} (ignored)`);
      return;
    }
    console.log(`🔓 Unlocking scroll for: ${lockId}. Active locks:`, Array.from(lockCountRef.current));
    lockCountRef.current.delete(lockId);
    updateBodyClass();
    console.log(`🔓 After unlock - Active locks:`, Array.from(lockCountRef.current));
  };

  const updateBodyClass = () => {
    if (typeof document !== 'undefined') {
      if (lockCountRef.current.size > 0) {
        console.log(`📱 Adding 'scroll-locked' class to body. Lock count: ${lockCountRef.current.size}`);
        document.body.classList.add('scroll-locked');
      } else {
        console.log(`📱 Removing 'scroll-locked' class from body. Lock count: ${lockCountRef.current.size}`);
        document.body.classList.remove('scroll-locked');
      }
    }
  };

  const contextValue: ScrollLockContextType = {
    lockScroll,
    unlockScroll,
  };

  return (
    <ScrollLockContext.Provider value={contextValue}>
      {children}
    </ScrollLockContext.Provider>
  );
};

export const useScrollLock = () => {
  const context = useContext(ScrollLockContext);
  if (!context) {
    throw new Error('useScrollLock must be used within a ScrollLockProvider');
  }
  return context;
};

// Custom hook for components that need to lock/unlock scroll
export const useScrollLockEffect = (lockId: string, shouldLock: boolean) => {
  const { lockScroll, unlockScroll } = useScrollLock();

  useEffect(() => {
    console.log(`🔄 useScrollLockEffect called for ${lockId} with shouldLock: ${shouldLock}`);
    if (shouldLock) {
      lockScroll(lockId);
    } else {
      unlockScroll(lockId);
    }

    // Cleanup on unmount
    return () => {
      console.log(`🧹 Cleanup running for ${lockId}`);
      unlockScroll(lockId);
    };
  }, [lockId, shouldLock, lockScroll, unlockScroll]);
};