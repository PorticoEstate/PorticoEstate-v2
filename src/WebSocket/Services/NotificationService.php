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
     * 
     * @return void
     */
    public function sendServerPing(): void
    {
        $clientCount = count($this->clients);
        
        // Only log if there are clients
        if ($clientCount > 0) {
            $this->logger->info("Server ping", [
                'recipients' => $clientCount,
                'timestamp' => date('c')
            ]);
            
            $pingMessage = json_encode([
                'type' => 'server_ping',
                'timestamp' => date('c')
            ]);
            
            $successCount = 0;
            $failCount = 0;
            
            foreach ($this->clients as $client) {
                try {
                    $client->send($pingMessage);
                    $successCount++;
                } catch (\Exception $e) {
                    $failCount++;
                    $this->logger->error("Ping failure", [
                        'clientId' => $client->resourceId,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Close the connection if we can't send a ping
                    try {
                        $client->close();
                    } catch (\Exception $e) {
                        // Ignore close errors
                    }
                }
            }
            
            // Log summary
            if ($failCount > 0) {
                $this->logger->info("Ping summary", [
                    'success' => $successCount,
                    'failed' => $failCount,
                    'total' => $clientCount
                ]);
            }
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
                case 'ping':
                    // Reply with a pong directly to the client to keep the connection alive
                    $from->send(json_encode([
                        'type' => 'pong',
                        'timestamp' => date('c')
                    ]));
                    $this->logger->info("Ping-Pong", [
                        'clientId' => $from->resourceId,
                        'action' => 'pong sent'
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