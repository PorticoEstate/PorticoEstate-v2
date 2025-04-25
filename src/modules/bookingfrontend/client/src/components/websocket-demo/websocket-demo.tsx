'use client'

import { useState, useEffect } from 'react';
import { useWebSocketContext } from '../../service/websocket/websocket-context';
import {Card, Paragraph, Heading, Button, Textfield} from '@digdir/designsystemet-react';
import styles from './websocket-demo.module.scss';

interface MessageItem {
  id: string;
  type: string;
  content: string;
  timestamp: Date;
  direction: 'in' | 'out';
}

export const WebSocketDemo: React.FC = () => {
  const { status, lastMessage, sendMessage } = useWebSocketContext();
  const [messages, setMessages] = useState<MessageItem[]>([]);
  const [messageInput, setMessageInput] = useState('');
  const [messageType, setMessageType] = useState('notification');
  const [isOpen, setIsOpen] = useState(false);

  // Add new message to the log when lastMessage updates
  useEffect(() => {
    if (lastMessage) {
      setMessages(prev => [
        {
          id: `in-${Date.now()}`,
          type: lastMessage.type,
          content: 'message' in lastMessage ? lastMessage.message : JSON.stringify(lastMessage),
          timestamp: new Date(),
          direction: 'in'
        },
        ...prev.slice(0, 19) // Keep only the last 20 messages
      ]);
    }
  }, [lastMessage]);

  const handleSendMessage = () => {
    if (!messageInput.trim()) return;

    const success = sendMessage(messageType, messageInput);

    if (success) {
      setMessages(prev => [
        {
          id: `out-${Date.now()}`,
          type: messageType,
          content: messageInput,
          timestamp: new Date(),
          direction: 'out'
        },
        ...prev.slice(0, 19) // Keep only the last 20 messages
      ]);

      setMessageInput('');
    }
  };

  const getStatusClass = () => {
    switch(status) {
      case 'OPEN': return styles.statusConnected;
      case 'CONNECTING':
      case 'RECONNECTING': return styles.statusConnecting;
      case 'ERROR':
      case 'CLOSED': return styles.statusDisconnected;
      default: return '';
    }
  };

  return (
    <Card>
      <div className={styles.header}>
        <Heading level={3} data-size="sm">WebSocket Connection</Heading>
        <div className={`${styles.status} ${getStatusClass()}`}>
          <span className={styles.statusDot} />
          <span>{status}</span>
        </div>
        <Button
          variant="tertiary"
          data-size="sm"
          onClick={() => setIsOpen(!isOpen)}
        >
          {isOpen ? 'Hide' : 'Show'}
        </Button>
      </div>

      <div >
        <div className={styles.demoContainer}>
          <div className={styles.messagesContainer}>
            {messages.length === 0 && (
              <Paragraph data-size="sm" className={styles.emptyState}>
                No messages yet. Try sending one!
              </Paragraph>
            )}

            {messages.map(msg => (
              <div
                key={msg.id}
                className={`${styles.message} ${msg.direction === 'out' ? styles.outgoing : styles.incoming}`}
              >
                <div className={styles.messageHeader}>
                  <span className={styles.messageType}>{msg.type}</span>
                  <span className={styles.messageTime}>
                    {msg.timestamp.toLocaleTimeString()}
                  </span>
                </div>
                <div className={styles.messageContent}>
                  {msg.content}
                </div>
              </div>
            ))}
          </div>

          <div className={styles.controls}>
            <div className={styles.inputRow}>
              <div className={styles.typeSelector}>
                <select
                  value={messageType}
                  onChange={(e) => setMessageType(e.target.value)}
                  className={styles.select}
                >
                  <option value="notification">Notification</option>
                  <option value="chat">Chat</option>
                  <option value="ping">Ping</option>
                  <option value="custom">Custom</option>
                </select>
              </div>

              <Textfield
                label="Message"
                value={messageInput}
                onChange={(e) => setMessageInput(e.target.value)}
                placeholder="Enter message..."
                className={styles.messageInput}
              />

              <Button
                variant="primary"
                disabled={status !== 'OPEN' || !messageInput.trim()}
                onClick={handleSendMessage}
              >
                Send
              </Button>
            </div>
          </div>
        </div>
      </div>
    </Card>
  );
};