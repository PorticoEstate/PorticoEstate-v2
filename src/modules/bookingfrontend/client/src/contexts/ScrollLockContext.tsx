'use client';
import React, { createContext, useContext, useEffect, useRef, ReactNode } from 'react';

interface ScrollLockContextType {
  lockScroll: (lockId: string) => void;
  unlockScroll: (lockId: string) => void;
}

const DEBUG_MODE: boolean = false;

const debug = (...args: any[]) => {
  if (DEBUG_MODE) {
    console.log(...args);
  }
};

const ScrollLockContext = createContext<ScrollLockContextType | undefined>(undefined);

interface ScrollLockProviderProps {
  children: ReactNode;
}

export const ScrollLockProvider: React.FC<ScrollLockProviderProps> = ({ children }) => {
  const lockCountRef = useRef(new Set<string>());

  const lockScroll = (lockId: string) => {
    const wasAlreadyLocked = lockCountRef.current.has(lockId);
    if (wasAlreadyLocked) {
      debug(`⚠️ Attempted duplicate lock for: ${lockId} (ignored)`);
      return;
    }
    debug(`🔒 Locking scroll for: ${lockId}. Active locks:`, Array.from(lockCountRef.current));
    lockCountRef.current.add(lockId);
    updateBodyClass();
    debug(`🔒 After lock - Active locks:`, Array.from(lockCountRef.current));
  };

  const unlockScroll = (lockId: string) => {
    const wasLocked = lockCountRef.current.has(lockId);
    if (!wasLocked) {
      debug(`⚠️ Attempted unlock for non-existent lock: ${lockId} (ignored)`);
      return;
    }
    debug(`🔓 Unlocking scroll for: ${lockId}. Active locks:`, Array.from(lockCountRef.current));
    lockCountRef.current.delete(lockId);
    updateBodyClass();
    debug(`🔓 After unlock - Active locks:`, Array.from(lockCountRef.current));
  };

  const updateBodyClass = () => {
    if (typeof document !== 'undefined') {
      if (lockCountRef.current.size > 0) {
        debug(`📱 Adding 'scroll-locked' class to body. Lock count: ${lockCountRef.current.size}`);
        document.body.classList.add('scroll-locked');
      } else {
        debug(`📱 Removing 'scroll-locked' class from body. Lock count: ${lockCountRef.current.size}`);
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
    debug(`🔄 useScrollLockEffect called for ${lockId} with shouldLock: ${shouldLock}`);
    if (shouldLock) {
      lockScroll(lockId);
    } else {
      unlockScroll(lockId);
    }

    // Cleanup on unmount
    return () => {
      debug(`🧹 Cleanup running for ${lockId}`);
      unlockScroll(lockId);
    };
  }, [lockId, shouldLock, lockScroll, unlockScroll]);
};