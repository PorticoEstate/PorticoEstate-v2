'use client'

import React, { useState, useCallback } from 'react';
import {
  useWebSocketNotification,
  useSendNotification
} from '../../service/websocket/notification-helper';
import {Card, Heading, Button, Paragraph, Alert, Textfield} from '@digdir/designsystemet-react';
import styles from './websocket-demo.module.scss';

export const NotificationExample: React.FC = () => {
  const [lastNotification, setLastNotification] = useState<string | null>(null);
  const [inputValue, setInputValue] = useState('');
  const sendNotification = useSendNotification();

  // Listen for application update notifications
  useWebSocketNotification('application_update', useCallback((data) => {
    setLastNotification(`Application ${data.applicationId} was ${data.status}: ${data.message}`);
  }, []));

  const [notificationType, setNotificationType] = useState('application_update');
  
  const handleSendTestNotification = () => {
    sendNotification(
      notificationType,
      inputValue || 'Application has been updated',
      {
        applicationId: Math.floor(Math.random() * 1000),
        status: 'updated',
        timestamp: new Date().toISOString()
      }
    );
    
    // Also show browser notification if permission is granted
    if ('Notification' in window && Notification.permission === 'granted') {
      let title = 'New Notification';
      
      switch(notificationType) {
        case 'application_update':
          title = 'Application Update';
          break;
        case 'new_message':
          title = 'New Message';
          break;
        case 'booking_confirmation':
          title = 'Booking Confirmed';
          break;
      }
      
      new Notification(title, {
        body: inputValue || 'Application has been updated',
        icon: '/logo_aktiv_kommune.png',
        badge: '/favicon-32x32.png'
      });
    }
  };

  return (
    <Card>
      <Heading level={3} data-size="sm">Notification Example</Heading>

      <Paragraph>
        This component demonstrates using WebSocket for application notifications.
        When an application status changes, all connected clients will receive a notification.
      </Paragraph>

      {lastNotification && (
        <Alert data-color="success" className={styles.notificationCard}>
          <Paragraph data-size="sm">{lastNotification}</Paragraph>
        </Alert>
      )}

      <div className={styles.controls}>
        <div style={{ marginBottom: '1rem' }}>
          <label style={{ display: 'block', marginBottom: '0.5rem' }}>Notification Type</label>
          <select
            value={notificationType}
            onChange={(e) => setNotificationType(e.target.value)}
            style={{ width: '100%', padding: '0.5rem', borderRadius: '4px', border: '1px solid #ccc' }}
          >
            <option value="application_update">Application Update</option>
            <option value="new_message">New Message</option>
            <option value="booking_confirmation">Booking Confirmation</option>
            <option value="info">Info (No Browser Notification)</option>
          </select>
        </div>
      
        <Textfield
          label="Notification message"
          value={inputValue}
          onChange={(e) => setInputValue(e.target.value)}
          placeholder="Enter notification message..."
        />

        <Button
          variant="primary"
          onClick={handleSendTestNotification}
          style={{ marginTop: '1rem' }}
        >
          Send Test Notification
        </Button>
      </div>
    </Card>
  );
};