<?php

namespace App\modules\bookingfrontend\helpers;

use Exception;

/**
 * Helper class for WebSocket operations in the bookingfrontend module
 */
class WebSocketHelper
{
    /**
     * WebSocket server host (default is localhost)
     * 
     * @var string
     */
    protected static $host = '127.0.0.1';
    
    /**
     * WebSocket server port
     * 
     * @var int
     */
    protected static $port = 8080;
    
    /**
     * Send a notification through the WebSocket server
     * 
     * @param string $message The notification message
     * @param array $data Additional data for the notification
     * @param string|null $host Override the default host
     * @param int|null $port Override the default port
     * @return bool True if sent successfully, false otherwise
     */
    public static function sendNotification(string $message, array $data = [], ?string $host = null, ?int $port = null): bool
    {
        $wsHost = $host ?? self::$host;
        $wsPort = $port ?? self::$port;
        
        try {
            $client = new WebSocketClient($wsHost, $wsPort);
            $client->connect()
                ->send([
                    'type' => 'notification',
                    'message' => $message,
                    'data' => $data,
                    'timestamp' => date('c')
                ])
                ->close();
            
            return true;
        } catch (Exception $e) {
            error_log("WebSocket notification error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Simple WebSocket client for internal connections
 */
class WebSocketClient
{
    /**
     * Socket resource
     * 
     * @var resource|null
     */
    private $socket;
    
    /**
     * WebSocket server host
     * 
     * @var string
     */
    private $host;
    
    /**
     * WebSocket server port
     * 
     * @var int
     */
    private $port;

    /**
     * Constructor
     * 
     * @param string $host WebSocket server host
     * @param int $port WebSocket server port
     */
    public function __construct(string $host = '127.0.0.1', int $port = 8080)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Connect to the WebSocket server
     * 
     * @return $this For method chaining
     * @throws Exception If connection fails
     */
    public function connect()
    {
        $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, 2);
        
        if (!$this->socket) {
            throw new Exception("Unable to connect to WebSocket server: $errstr ($errno)");
        }

        return $this;
    }

    /**
     * Send data to the WebSocket server
     * 
     * @param array $data Data to send
     * @return $this For method chaining
     * @throws Exception If not connected or send fails
     */
    public function send(array $data)
    {
        if (!$this->socket) {
            throw new Exception("Not connected to WebSocket server");
        }

        $message = json_encode($data);
        
        fputs($this->socket, $message);
        
        return $this;
    }

    /**
     * Close the connection
     * 
     * @return $this For method chaining
     */
    public function close()
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        
        return $this;
    }
}