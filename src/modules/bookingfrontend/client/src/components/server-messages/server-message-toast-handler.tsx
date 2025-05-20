'use client';
import React, { FC, useEffect, useRef } from 'react';
import { useDeleteServerMessage, useServerMessages } from "@/service/hooks/api-hooks";
import { useToast } from "@/components/toast/toast-context";
import { IServerMessage } from "@/service/types/api/server-messages.types";
import { useTrans } from "@/app/i18n/ClientTranslationProvider";
import { useWebSocketContext } from "@/service/websocket/websocket-context";
import { usePathname } from "next/navigation";
import { useQueryClient } from '@tanstack/react-query';
import ServerMessages from "./server-messages";

/**
 * This component handles server messages by either:
 * 1. Displaying them as toasts when WebSocket is connected (and deleting them after)
 * 2. Falling back to the original ServerMessages component when WebSocket is not connected
 *
 * When WebSocket is not connected, it also invalidates and refetches server messages
 * whenever the user navigates to a different page.
 */
const ServerMessageToastHandler: FC = () => {
  const { data: messages, refetch } = useServerMessages();
  const { addToast } = useToast();
  const deleteMessages = useDeleteServerMessage();
  const t = useTrans();
  const { status, sessionConnected } = useWebSocketContext();
  const pathname = usePathname();
  const queryClient = useQueryClient();
  // Keep track of which message IDs we've already processed to avoid duplicates
  const processedMessageIds = useRef<Set<string>>(new Set());

  // Check if WebSocket is fully connected
  const isWebSocketConnected = status === 'OPEN' && sessionConnected;

  // Process messages as toasts when WebSocket is connected
  useEffect(() => {
    if (isWebSocketConnected && messages && messages.length > 0) {
      messages.forEach((message: IServerMessage) => {
        // Skip if we've already processed this message ID
        if (processedMessageIds.current.has(message.id)) {
          return;
        }
        
        // Mark this message as processed
        processedMessageIds.current.add(message.id);
        
        // Map server message type to toast type
        const toastType = message.type === 'error' ? 'error' : 'success';
        
        // Determine if we should auto-hide and configuration based on message type
        // Error messages stay longer on screen than success messages
        const autoHide = true; // Always auto-hide, but duration is controlled in toast-context.tsx

        // Add as toast
        addToast({
          type: toastType,
          title: message.title ? t(message.title) : undefined,
          text: message.text,
          autoHide: autoHide,
          messageId: `server-message-${message.id}` // Use messageId for deduplication
        });

        // Delete the server message now that it's been displayed as a toast
        // Set retry: false to prevent retrying if the mutation fails
        deleteMessages.mutate(message.id, { 
          retry: false,
          onError: (error) => {
            console.error(`Failed to delete server message ${message.id}:`, error);
          }
        });
      });
    }
  }, [messages, addToast, deleteMessages, t, isWebSocketConnected]);

  // Clean up the processed IDs set when WebSocket disconnects to avoid memory leaks
  useEffect(() => {
    if (!isWebSocketConnected) {
      processedMessageIds.current.clear();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isWebSocketConnected]);

  // Refetch server messages on navigation when WebSocket is not connected
  useEffect(() => {
    // Only refetch when WebSocket is not connected
    if (!isWebSocketConnected) {
      // Invalidate the server messages query and refetch
      queryClient.invalidateQueries({queryKey: ['serverMessages']});
    }
  }, [pathname, isWebSocketConnected, queryClient]);

  // If WebSocket is not connected, fall back to original ServerMessages component
  if (!isWebSocketConnected) {
    return <ServerMessages />;
  }

  // When WebSocket is connected, this component doesn't render anything visible
  // (messages are shown as toasts)
  return null;
};

export default ServerMessageToastHandler;