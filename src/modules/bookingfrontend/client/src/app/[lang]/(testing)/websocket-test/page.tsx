'use client';

import React from 'react';
import { WebSocketStatusIndicator } from '@/service/websocket/websocket-context';
import { WebSocketDemo, NotificationExample } from '@/components/websocket-demo';
import { Heading, Paragraph, Link, Button } from '@digdir/designsystemet-react';
import { ServiceWorkerDebug } from './debug';
import { WebSocketModeSwitcher } from './mode-switcher';
import { FallbackIndicator } from '@/service/websocket/fallback-indicator';

export default function WebSocketTestPage() {
  // Function to request browser notification permission
  const requestNotificationPermission = async () => {
    if (!('Notification' in window)) {
      alert('This browser does not support desktop notifications');
      return;
    }

    if (Notification.permission === 'granted') {
      alert('Notification permission already granted');
      return;
    }

    const permission = await Notification.requestPermission();
    alert(`Notification permission: ${permission}`);
  };

  return (
    <div style={{maxWidth: '800px', margin: '0 auto', padding: '2rem 1rem'}}>
      <Heading level={1} data-size="lg">WebSocket Test Page</Heading>

      <Paragraph>
        This page demonstrates the WebSocket connection feature.
        The WebSocket client automatically connects to the server and maintains
        the connection with ping/pong messages.
      </Paragraph>

      {/* Show automatic fallback indicator if it occurred */}
      <FallbackIndicator />

      {/* Add the mode switcher */}
      <WebSocketModeSwitcher />

      <div style={{display: 'flex', alignItems: 'center', gap: '1rem', margin: '1rem 0'}}>
        <WebSocketStatusIndicator />

        <Button
          variant="secondary"
          data-size={'sm'}
          onClick={requestNotificationPermission}
        >
          Request Notification Permission
        </Button>
      </div>

      <Paragraph>
        <Link href="/">Return to home page</Link>
      </Paragraph>

      <div style={{display: 'flex', flexDirection: 'column', gap: '2rem'}}>
        <WebSocketDemo/>
        <NotificationExample/>
        <ServiceWorkerDebug/>
      </div>
    </div>
  );
}