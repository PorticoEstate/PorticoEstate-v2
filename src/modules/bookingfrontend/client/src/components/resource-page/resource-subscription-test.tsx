'use client';

import React, { useState, useCallback, useRef } from 'react';
import { useEntitySubscription } from '@/service/hooks/use-websocket-subscriptions';
import { Alert, Heading, Paragraph } from '@digdir/designsystemet-react';
import { Button } from '@digdir/designsystemet-react';
import { ChevronDownIcon, ChevronUpIcon } from "@navikt/aksel-icons";
import { WebSocketMessage } from '@/service/websocket/websocket.types';

interface ResourceSubscriptionTestProps {
  resourceId: number;
}

// Create a component that prevents unnecessary re-renders
const ResourceSubscriptionTest: React.FC<ResourceSubscriptionTestProps> = ({ resourceId }) => {
  console.log('ResourceSubscriptionTest rendering for resource:', resourceId);
  const [expanded, setExpanded] = useState(false);
  const [events, setEvents] = useState<Array<{ message: string; timestamp: string }>>([]);

  // Create a stable callback with useCallback that doesn't change on re-renders

  // Helper function to format the message event for display
  const formatMessage = useCallback((message: WebSocketMessage) => {
    // Handle room_message format specifically (using type predicate for TypeScript)
    if (message.type === 'room_message') {
      // Format the data in a more readable way
      let formattedData = 'No data';
      if (message.data) {
        try {
          // For reservation data, give a more user-friendly format
          if (message.action === 'updated' && message.data.from && message.data.to) {
            const from = new Date(message.data.from).toLocaleString();
            const to = new Date(message.data.to).toLocaleString();

            formattedData = `Reservation ID: ${message.data.application_id || 'N/A'}\n` +
                            `From: ${from}\n` +
                            `To: ${to}\n` +
                            `Other data: ${JSON.stringify(message.data, null, 2)}`;
          } else {
            formattedData = JSON.stringify(message.data, null, 2);
          }
        } catch (e) {
          formattedData = String(message.data);
        }
      }

      return `📣 ${message.message || 'Resource update'}\n` +
             `Action: ${message.action || 'unknown'}\n` +
             `Entity: ${message.entityType} ${message.entityId}\n` +
             `Room: ${message.roomId}\n` +
             `Time: ${new Date(message.timestamp).toLocaleString()}\n` +
             `Data:\n${formattedData}`;
    }

    // Default format for other message types
    return `Type: ${message.type}\n` +
           `Action: ${message.action || '-'}\n` +
           `Timestamp: ${new Date(message.timestamp).toLocaleString()}\n` +
           `Data: ${JSON.stringify(message, null, 2)}`;
  }, []);

  // Subscribe to the resource entity with a stable callback
  const { isSubscribed } = useEntitySubscription(
    'resource',
    resourceId,
    useCallback((message: WebSocketMessage) => {
      console.log('Received resource update:', message);

      // Add the new event to the events list
      setEvents((prevEvents) => [
        {
          message: formatMessage(message),
          timestamp: new Date().toISOString(),
        },
        ...prevEvents,
      ].slice(0, 10)); // Keep only the latest 10 events
    }, [formatMessage])
  );

  // Create a stable toggle function that doesn't recreate on each render
  const toggleExpanded = useCallback(() => {
    setExpanded(prev => !prev);
  }, []);

  return (
    <div className="my-4 border border-gray-200 rounded-lg">
      <Button
        onClick={toggleExpanded}
        className="w-full flex justify-between items-center p-3"
        aria-expanded={expanded}
      >
        <span>Resource Subscription Test (ID: {resourceId})</span>
        {expanded ? <ChevronUpIcon width={20} /> : <ChevronDownIcon width={20} />}
      </Button>

      {expanded && (
        <div className="p-4 border-t border-gray-200">
          <Alert data-color={isSubscribed ? "success" : "warning"}>
            <Heading level={2} >
              WebSocket Subscription Status
            </Heading>
            <Paragraph>
              {isSubscribed
                ? `✅ Subscribed to resource ${resourceId}`
                : `⚠️ Not subscribed to resource ${resourceId}`}
            </Paragraph>
          </Alert>

          <div className="mt-4">
            <Heading level={2} >Resource Events ({events.length})</Heading>
            {events.length === 0 ? (
              <Paragraph className="italic text-gray-500 mt-2">
                No events received yet. Try making a change to this resource.
              </Paragraph>
            ) : (
              <ul className="mt-2 space-y-3">
                {events.map((event, index) => {
                  // Determine the type of message for styling
                  const isReservationUpdate = event.message.includes('Timeslot reservation');
                  const isRoomMessage = event.message.includes('Room:');

                  // Choose styling based on message type
                  let cardStyle = "border-gray-100 bg-gray-50";
                  let badgeText = "Event";
                  let badgeColor = "text-gray-500";

                  if (isReservationUpdate) {
                    cardStyle = "border-blue-200 bg-blue-50";
                    badgeText = "Reservation Update";
                    badgeColor = "text-blue-700";
                  } else if (isRoomMessage) {
                    cardStyle = "border-purple-200 bg-purple-50";
                    badgeText = "Room Message";
                    badgeColor = "text-purple-700";
                  }

                  return (
                    <li
                      key={index}
                      className={`border rounded p-3 text-sm ${cardStyle}`}
                    >
                      <div className="text-xs font-semibold mb-1 flex justify-between">
                        <span>
                          {new Date(event.timestamp).toLocaleTimeString()}
                        </span>
                        <span className={`${badgeColor} px-2 py-0.5 rounded-full bg-opacity-20 ${badgeColor.replace('text', 'bg')}`}>
                          {badgeText}
                        </span>
                      </div>
                      <pre className="whitespace-pre-wrap text-sm font-mono leading-relaxed">
                        {event.message}
                      </pre>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default ResourceSubscriptionTest;