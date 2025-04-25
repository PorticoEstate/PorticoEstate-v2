'use client';

import React, { useEffect, useState } from 'react';
import { Button } from '@digdir/designsystemet-react';

export const WebSocketModeSwitcher: React.FC = () => {
  const [isDirect, setIsDirect] = useState(false);
  
  useEffect(() => {
    // Determine the current mode from URL
    const urlParams = new URLSearchParams(window.location.search);
    const direct = urlParams.get('direct');
    setIsDirect(direct === 'true' || direct === '1');
  }, []);
  
  const switchMode = () => {
    // Get the current URL and update the direct parameter
    const url = new URL(window.location.href);
    const newIsDirect = !isDirect;
    
    if (newIsDirect) {
      url.searchParams.set('direct', 'true');
    } else {
      url.searchParams.delete('direct');
    }
    
    // Navigate to the new URL
    window.location.href = url.toString();
  };
  
  return (
    <div style={{
      marginBottom: '20px',
      display: 'flex',
      alignItems: 'center',
      gap: '10px'
    }}>
      <strong>Connection Mode:</strong>
      <Button 
        variant={isDirect ? 'secondary' : 'primary'}
        onClick={switchMode}
        style={{ minWidth: '150px' }}
      >
        {isDirect ? 'Switch to Service Worker' : 'Switch to Direct Mode'}
      </Button>
      <span style={{ 
        fontSize: '14px',
        fontStyle: 'italic',
        color: '#666'
      }}>
        {isDirect 
          ? 'Currently using direct WebSocket connection without service workers' 
          : 'Currently using service worker for WebSocket connection when available'}
      </span>
    </div>
  );
};