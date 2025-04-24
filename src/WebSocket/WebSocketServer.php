<?php

namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Log\LoggerInterface;

class WebSocketServer implements MessageComponentInterface
{
    protected $clients;
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->clients = new \SplObjectStorage;
        $this->logger = $logger;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $this->logger->info("New connection! ({$conn->resourceId})");
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $numRecv = count($this->clients) - 1;
        $this->logger->info(sprintf('Connection %d sending message "%s" to %d other connection%s', 
            $from->resourceId, $msg, $numRecv, $numRecv == 1 ? '' : 's'));

        $data = json_decode($msg, true);
        
        // Process message based on type
        if (isset($data['type'])) {
            switch ($data['type']) {
                case 'chat':
                    $this->broadcastMessage($from, $msg);
                    break;
                case 'notification':
                    $this->broadcastNotification($msg);
                    break;
                case 'ping':
                    // Reply with a pong directly to the client to keep the connection alive
                    $from->send(json_encode([
                        'type' => 'pong',
                        'timestamp' => date('c')
                    ]));
                    $this->logger->info("Ping received from client {$from->resourceId}, sent pong response");
                    break;
                default:
                    $this->broadcastMessage($from, $msg);
            }
        } else {
            $this->broadcastMessage($from, $msg);
        }
    }

    protected function broadcastMessage(ConnectionInterface $from, $msg)
    {
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
    public function broadcastNotification($msg)
    {
        // Convert array to JSON if needed
        if (is_array($msg)) {
            $msg = json_encode($msg);
        }
        
        $this->logger->info("Broadcasting notification to " . count($this->clients) . " clients");
        
        foreach ($this->clients as $client) {
            try {
                $client->send($msg);
            } catch (\Exception $e) {
                $this->logger->error("Error sending to client {$client->resourceId}: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Check for notification files in the temporary directory
     * 
     * This is a workaround for not having an HTTP server
     * 
     * @return void
     */
    public function checkNotificationFiles()
    {
        $this->logger->info("Checking for notification files");
        $files = glob('/tmp/websocket_notification_*.json');
        
        if (!empty($files)) {
            $this->logger->info("Found " . count($files) . " notification files");
            
            foreach ($files as $file) {
                try {
                    $content = file_get_contents($file);
                    $this->logger->info("Processing notification file: " . basename($file));
                    
                    if ($content) {
                        // Broadcast the notification
                        $this->broadcastNotification($content);
                        
                        // Delete the file
                        unlink($file);
                        $this->logger->info("Processed and deleted notification file: " . basename($file));
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Error processing notification file {$file}: " . $e->getMessage());
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
        $this->logger->info("Sending server ping to " . count($this->clients) . " clients");
        
        $pingMessage = json_encode([
            'type' => 'server_ping',
            'timestamp' => date('c')
        ]);
        
        foreach ($this->clients as $client) {
            try {
                $client->send($pingMessage);
            } catch (\Exception $e) {
                $this->logger->error("Error sending ping to client {$client->resourceId}: " . $e->getMessage());
                // Close the connection if we can't send a ping
                try {
                    $client->close();
                } catch (\Exception $e) {
                    // Ignore close errors
                }
            }
        }
    }
    
    /**
     * Get the count of connected clients
     * 
     * @return int Number of connected clients
     */
    public function getClientCount(): int
    {
        return count($this->clients);
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        $this->logger->info("Connection {$conn->resourceId} has disconnected");
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->logger->error("An error has occurred: {$e->getMessage()}");
        $conn->close();
    }
}