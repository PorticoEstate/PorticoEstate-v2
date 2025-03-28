<?php

/**
 * Example script to send a notification through the WebSocket server
 * 
 * This can be used from other parts of the application to send real-time
 * notifications to connected clients.
 */

// Simple client to connect to WebSocket server
class WebSocketClient
{
    private $socket;
    private $host;
    private $port;

    public function __construct(string $host = '127.0.0.1', int $port = 8080)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function connect()
    {
        $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, 5);
        
        if (!$this->socket) {
            throw new \Exception("Unable to connect to WebSocket server: $errstr ($errno)");
        }

        return $this;
    }

    public function send(array $data)
    {
        if (!$this->socket) {
            throw new \Exception("Not connected to WebSocket server");
        }

        // WebSocket protocol requires a handshake, but for simple internal communication
        // we can just send the message directly on port 8080 since we control both sides
        $message = json_encode($data);
        
        fputs($this->socket, $message);
        
        return $this;
    }

    public function close()
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        
        return $this;
    }
}

/**
 * Helper function to send notifications
 */
function sendWebSocketNotification(string $message, array $data = [], string $host = '127.0.0.1', int $port = 8080)
{
    try {
        $client = new WebSocketClient($host, $port);
        $client->connect()
            ->send([
                'type' => 'notification',
                'message' => $message,
                'data' => $data,
                'timestamp' => date('c')
            ])
            ->close();
        
        return true;
    } catch (\Exception $e) {
        error_log("WebSocket notification error: " . $e->getMessage());
        return false;
    }
}

// Example usage:
// sendWebSocketNotification('New booking created', ['id' => 123, 'name' => 'Test Booking']);