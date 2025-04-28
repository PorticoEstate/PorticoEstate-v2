'use client';

import React, { useState, useEffect, useCallback } from 'react';
import { WebSocketService } from '../../service/websocket/websocket-service';
import { WebSocketMessage, WebSocketStatus } from '../../service/websocket/websocket.types';
import { useEntitySubscription, useMessageTypeSubscription } from '../../service/hooks/use-websocket-subscriptions';
import styles from './websocket-demo.module.scss';

/**
 * WebSocket Room Subscription Demo Component
 * 
 * Demonstrates subscribing to rooms (entities) and message types
 */
export const RoomSubscriptionDemo: React.FC = () => {
  // State for the room subscription form
  const [entityType, setEntityType] = useState<string>('resource');
  const [entityId, setEntityId] = useState<string>('');
  const [subscriptionStatus, setSubscriptionStatus] = useState<string>('Not subscribed');

  // State for messages
  const [messages, setMessages] = useState<WebSocketMessage[]>([]);
  const [connectionStatus, setConnectionStatus] = useState<WebSocketStatus>('CLOSED');
  
  // Get WebSocket service instance
  const wsService = WebSocketService.getInstance();

  // Initialize WebSocket on component mount
  useEffect(() => {
    if (!wsService.isReady()) {
      wsService.initialize({
        url: `${window.location.protocol === 'https:' ? 'wss:' : 'ws:'}//${window.location.host}/wss`
      });
    }

    // Set up status listener
    const statusListener = (data: { status: WebSocketStatus }) => {
      setConnectionStatus(data.status);
    };

    wsService.addEventListener('status', statusListener);

    return () => {
      wsService.removeEventListener('status', statusListener);
    };
  }, []);

  // Generic message handler to display messages
  const handleMessage = useCallback((message: WebSocketMessage) => {
    setMessages(prev => [message, ...prev].slice(0, 10)); // Keep last 10 messages
  }, []);

  // Subscribe to entity events when a specific resource is viewed
  const handleEntityMessage = useCallback((message: WebSocketMessage) => {
    // Add the message to our list
    setMessages(prev => [{
      ...message,
      message: `Entity event: ${message.type} for ${(message as any).entityType || 'unknown'} ${(message as any).entityId || 'unknown'}`
    }, ...prev].slice(0, 10));
  }, []);

  // Use hooks to subscribe to entities and message types
  const { unsubscribe, isSubscribed } = useEntitySubscription(
    entityType,
    entityId || '0', // Default to 0 if no ID is provided
    handleEntityMessage
  );

  // Also subscribe to all notifications
  useMessageTypeSubscription('notification', handleMessage);

  // Form submission to subscribe to a specific entity
  const handleSubscribe = (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!entityId || !entityType) {
      alert('Please enter both entity type and ID');
      return;
    }

    // Manually subscribe (the hook above will handle this automatically,
    // but this demonstrates manual subscription)
    wsService.subscribeToRoom(entityType, entityId, handleEntityMessage);
    setSubscriptionStatus(`Subscribed to ${entityType} ${entityId}`);
  };

  // Unsubscribe from the entity
  const handleUnsubscribe = () => {
    if (!entityId || !entityType) return;
    
    // Manually unsubscribe
    wsService.unsubscribeFromRoom(entityType, entityId);
    setSubscriptionStatus('Not subscribed');
  };

  // Send a test entity message
  const sendTestEntityEvent = () => {
    wsService.sendMessage('entity_event', `Test entity event for ${entityType} ${entityId}`, {
      entityType,
      entityId,
      eventType: 'update',
      data: {
        timestamp: new Date().toISOString(),
        testField: 'This is a test entity update'
      }
    });
  };

  // Send a test notification
  const sendTestNotification = () => {
    wsService.sendMessage('notification', 'This is a test notification', {
      notificationType: 'info'
    });
  };

  return (
    <div className={styles.demoContainer}>
      <h3>WebSocket Room Subscription Demo</h3>
      
      <div className={styles.statusIndicator}>
        Connection Status: <span className={connectionStatus === 'OPEN' ? styles.connected : styles.disconnected}>
          {connectionStatus}
        </span>
      </div>
      
      <form onSubmit={handleSubscribe} className={styles.subscriptionForm}>
        <div>
          <label htmlFor="entityType">Entity Type:</label>
          <select 
            id="entityType"
            value={entityType} 
            onChange={(e) => setEntityType(e.target.value)}
          >
            <option value="resource">Resource</option>
            <option value="building">Building</option>
            <option value="allocation">Allocation</option>
            <option value="application">Application</option>
          </select>
        </div>
        
        <div>
          <label htmlFor="entityId">Entity ID:</label>
          <input 
            type="text" 
            id="entityId"
            value={entityId} 
            onChange={(e) => setEntityId(e.target.value)} 
            placeholder="Enter ID"
          />
        </div>
        
        <div className={styles.buttonContainer}>
          <button type="submit">Subscribe</button>
          <button type="button" onClick={handleUnsubscribe}>Unsubscribe</button>
        </div>
      </form>
      
      <div className={styles.statusIndicator}>
        Subscription Status: {subscriptionStatus}
      </div>
      
      <div className={styles.testButtons}>
        <button 
          onClick={sendTestEntityEvent} 
          disabled={connectionStatus !== 'OPEN'}
        >
          Send Test Entity Event
        </button>
        <button 
          onClick={sendTestNotification} 
          disabled={connectionStatus !== 'OPEN'}
        >
          Send Test Notification
        </button>
      </div>
      
      <div className={styles.messagesContainer}>
        <h4>Received Messages:</h4>
        <ul>
          {messages.map((msg, index) => (
            <li key={index} className={styles.message}>
              <div><strong>Type:</strong> {msg.type}</div>
              <div><strong>Message:</strong> {msg.message}</div>
              <div><strong>Timestamp:</strong> {msg.timestamp}</div>
              {msg.type === 'entity_event' && (
                <div className={styles.entityData}>
                  <div><strong>Entity:</strong> {(msg as any).entityType} {(msg as any).entityId}</div>
                  <div><strong>Event:</strong> {(msg as any).eventType}</div>
                </div>
              )}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
};