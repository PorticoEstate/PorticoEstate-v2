<?php

namespace App\WebSocket\Services;

use Psr\Log\LoggerInterface;
use Ratchet\ConnectionInterface;
use SplObjectStorage;

class NotificationService
{
    private $logger;
    private $clients;

    public function __construct(LoggerInterface $logger, SplObjectStorage $clients)
    {
        $this->logger = $logger;
        $this->clients = $clients;
    }

    /**
     * Broadcast a message to all clients except the sender
     *
     * @param ConnectionInterface $from Sender connection
     * @param string $msg Message to broadcast
     * @return void
     */
    public function broadcastMessage(ConnectionInterface $from, $msg): void
    {
        // Add timestamp to message if it's JSON and doesn't already have one
        if (is_string($msg) && $msg[0] === '{') {
            try {
                $data = json_decode($msg, true);
                if ($data && !isset($data['timestamp'])) {
                    // Add timestamp at the top level for all message types
                    $data['timestamp'] = date('c'); // ISO 8601 format
                    $msg = json_encode($data);
                }
            } catch (\Exception $e) {
                // If json decoding fails, continue with original message
            }
        }
        
        foreach ($this->clients as $client) {
            if ($from !== $client) {
                $client->send($msg);
            }
        }
    }

    /**
     * Broadcast a notification to all connected clients
     * 
     * @param string|array $msg The message to broadcast (JSON string or array)
     * @return void
     */
    public function broadcastNotification($msg): void
    {
        // Record start time for performance logging
        $startTime = microtime(true);
        
        // Parse message for logging if it's a JSON string
        $msgData = null;
        $msgType = 'unknown';
        $msgSource = 'unknown';
        
        if (is_string($msg)) {
            try {
                $msgData = json_decode($msg, true);
                $msgType = $msgData['type'] ?? 'unknown';
                $msgSource = $msgData['source'] ?? 'unknown';
                
                // Add timestamp if not present
                if (!isset($msgData['timestamp'])) {
                    $msgData['timestamp'] = date('c');
                    $msg = json_encode($msgData);
                }
            } catch (\Exception $e) {
                // Just continue if parsing fails
            }
        }
        
        // Convert array to JSON if needed
        if (is_array($msg)) {
            $msgType = $msg['type'] ?? 'unknown';
            $msgSource = $msg['source'] ?? 'unknown';
            
            // Add timestamp if not present
            if (!isset($msg['timestamp'])) {
                $msg['timestamp'] = date('c');
            }
            
            $msg = json_encode($msg);
        }
        
        $clientCount = count($this->clients);
        
        // Enhanced logging with message details
        $this->logger->info("Broadcasting notification", [
            'clients' => $clientCount,
            'type' => $msgType,
            'source' => $msgSource,
            'size' => strlen(is_string($msg) ? $msg : '0'),
            'timestamp' => date('c')
        ]);
        
        // Skip processing if no clients
        if ($clientCount === 0) {
            $this->logger->info("No clients connected, skipping broadcast");
            return;
        }
        
        $successCount = 0;
        $failCount = 0;
        
        foreach ($this->clients as $client) {
            try {
                $client->send($msg);
                $successCount++;
            } catch (\Exception $e) {
                $failCount++;
                $this->logger->error("Error sending to client {$client->resourceId}", [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode()
                ]);
            }
        }
        
        // Calculate elapsed time
        $elapsed = (microtime(true) - $startTime) * 1000; // in ms
        
        // Log broadcasting results
        $this->logger->info("Broadcast completed", [
            'success' => $successCount,
            'failed' => $failCount,
            'total' => $clientCount,
            'elapsed_ms' => round($elapsed, 2)
        ]);
    }

    /**
     * Check for notification files in the temporary directory
     * This is a fallback mechanism when Redis is not available
     * 
     * @return void
     */
    public function checkNotificationFiles(): void
    {
        // Only log when files are found to reduce noise
        $files = glob('/tmp/websocket_notification_*.json');
        
        if (!empty($files)) {
            $this->logger->info("Processing notification files", [
                'count' => count($files),
                'files' => array_map('basename', $files)
            ]);
            
            foreach ($files as $file) {
                try {
                    $content = file_get_contents($file);
                    
                    if ($content) {
                        // Try to decode the content for better logging
                        $data = json_decode($content, true);
                        $messageType = $data['type'] ?? 'unknown';
                        $notificationType = $data['notificationType'] ?? 'general';
                        
                        $this->logger->info("Broadcasting notification", [
                            'file' => basename($file),
                            'type' => $messageType,
                            'notificationType' => $notificationType,
                            'recipients' => count($this->clients)
                        ]);
                        
                        // Broadcast the notification
                        $this->broadcastNotification($content);
                        
                        // Delete the file
                        unlink($file);
                        $this->logger->info("Notification processed", [
                            'file' => basename($file),
                            'status' => 'deleted'
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Notification processing error", [
                        'file' => basename($file),
                        'error' => $e->getMessage(),
                        'errorCode' => $e->getCode()
                    ]);
                }
            }
        }
    }

    /**
     * Send a server ping to all clients to keep connections alive
     * This happens every minute, and the client should respond with a pong
     * 
     * @return void
     */
    public function sendServerPing(): void
    {
        $clientCount = count($this->clients);
        $startTime = microtime(true);
        
        // Enhanced logging for keepalive diagnostics
        $this->logger->info("Starting server ping process", [
            'recipients' => $clientCount,
            'timestamp' => date('c')
        ]);
        
        if ($clientCount > 0) {
            $pingMessage = json_encode([
                'type' => 'server_ping',
                'timestamp' => date('c'),
                'id' => uniqid('ping_')  // Add a unique ID to track individual pings
            ]);
            
            $successCount = 0;
            $failCount = 0;
            
            foreach ($this->clients as $client) {
                try {
                    $client->send($pingMessage);
                    $successCount++;
                    
                    // Add connection info to diagnostic data
                    if (isset($client->resourceId)) {
                        $this->logger->debug("Ping sent to client", [
                            'clientId' => $client->resourceId,
                            'sessionActive' => isset($client->sessionId),
                            'hasBookingSession' => isset($client->bookingSessionId),
                            'connectionAge' => isset($client->connectTime) ? (time() - $client->connectTime) . 's' : 'unknown'
                        ]);
                    }
                } catch (\Exception $e) {
                    $failCount++;
                    $this->logger->error("Ping failure", [
                        'clientId' => $client->resourceId,
                        'error' => $e->getMessage(),
                        'errorCode' => $e->getCode()
                    ]);
                    
                    // Close the connection if we can't send a ping
                    try {
                        $client->close();
                    } catch (\Exception $e) {
                        // Ignore close errors
                    }
                }
            }
            
            // Calculate elapsed time
            $elapsed = (microtime(true) - $startTime) * 1000; // in ms
            
            // Always log summary for keepalive diagnostics
            $this->logger->info("Ping summary", [
                'success' => $successCount,
                'failed' => $failCount,
                'total' => $clientCount,
                'elapsed_ms' => round($elapsed, 2)
            ]);
        } else {
            $this->logger->info("No clients connected for ping");
        }
    }

    /**
     * Process a message based on type
     *
     * @param ConnectionInterface $from Sender connection
     * @param string $message Raw message
     * @return void
     */
    public function processMessage(ConnectionInterface $from, string $message): void
    {
        $data = json_decode($message, true);
        $messageType = $data['type'] ?? 'unknown';
        
        // Add timestamp to the message if missing
        if (!isset($data['timestamp'])) {
            // Add timestamp at the top level
            $data['timestamp'] = date('c');
            $message = json_encode($data);
        }
        
        // Process message based on type
        if (isset($data['type'])) {
            switch ($data['type']) {
                case 'chat':
                    $this->logger->info("Chat message", [
                        'clientId' => $from->resourceId,
                        'message' => $data['message'] ?? 'no content'
                    ]);
                    $this->broadcastMessage($from, $message);
                    break;
                case 'notification':
                    $this->logger->info("Notification message", [
                        'clientId' => $from->resourceId,
                        'notificationType' => $data['notificationType'] ?? 'general',
                        'message' => $data['message'] ?? 'no content'
                    ]);
                    $this->broadcastNotification($message);
                    break;
                case 'entity_event':
                    // Entity events are now handled directly in WebSocketServer
                    // to ensure proper room-based routing
                    $this->logger->info("Entity event message - skipping NotificationService processing", [
                        'clientId' => $from->resourceId,
                        'message' => 'Entity events are now routed through WebSocketServer'
                    ]);
                    break;
                case 'ping':
                    // Generate a unique pong ID to track responses
                    $pongId = uniqid('pong_');
                    
                    // Reply with a pong directly to the client to keep the connection alive
                    $from->send(json_encode([
                        'type' => 'pong',
                        'timestamp' => date('c'),
                        'id' => $pongId,
                        'reply_to' => $data['id'] ?? null, // Echo back the ping ID if any
                        'heartbeat_id' => $data['heartbeat_id'] ?? null // Track client heartbeat ID
                    ]));
                    
                    // Enhanced logging with ping details
                    $pingInfo = [
                        'clientId' => $from->resourceId,
                        'action' => 'pong sent',
                        'pongId' => $pongId,
                        'replyTo' => $data['id'] ?? 'unknown',
                        'sessionActive' => isset($from->sessionId)
                    ];
                    
                    // Add heartbeat info if present
                    if (isset($data['heartbeat_id'])) {
                        $pingInfo['heartbeat_id'] = $data['heartbeat_id'];
                        $pingInfo['heartbeat_count'] = $data['count'] ?? 'unknown';
                    }
                    
                    $this->logger->info("Ping-Pong", $pingInfo);
                    break;
                case 'room_ping_response':
                    // Room ping responses are handled in WebSocketServer
                    $this->logger->debug("Room ping response received via NotificationService", [
                        'clientId' => $from->resourceId,
                        'action' => 'forwarded to WebSocketServer'
                    ]);
                    break;
                default:
                    $this->logger->info("Unknown message type", [
                        'clientId' => $from->resourceId,
                        'type' => $data['type']
                    ]);
                    $this->broadcastMessage($from, $message);
            }
        } else {
            $this->logger->info("Message without type", [
                'clientId' => $from->resourceId
            ]);
            $this->broadcastMessage($from, $message);
        }
    }
}