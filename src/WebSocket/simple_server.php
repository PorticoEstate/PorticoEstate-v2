<?php
// Simple WebSocket Server for testing

// Error handling
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Create a log file in a secure location
$logFile = __DIR__ . '/websocket_debug.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Starting WebSocket server\n", FILE_APPEND);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

// Simple message handler
class SimpleEcho implements MessageComponentInterface {
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        file_put_contents($GLOBALS['logFile'], date('Y-m-d H:i:s') . " - Server initialized\n", FILE_APPEND);
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        file_put_contents($GLOBALS['logFile'], date('Y-m-d H:i:s') . " - New connection: {$conn->resourceId}\n", FILE_APPEND);
        echo "New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        foreach ($this->clients as $client) {
            if ($from !== $client) {
                $client->send($msg);
            }
        }
        file_put_contents($GLOBALS['logFile'], date('Y-m-d H:i:s') . " - Message from {$from->resourceId}: $msg\n", FILE_APPEND);
        echo "Message from {$from->resourceId}: $msg\n";
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        file_put_contents($GLOBALS['logFile'], date('Y-m-d H:i:s') . " - Connection {$conn->resourceId} has disconnected\n", FILE_APPEND);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
        file_put_contents($GLOBALS['logFile'], date('Y-m-d H:i:s') . " - Error with connection {$conn->resourceId}: {$e->getMessage()}\n", FILE_APPEND);
        echo "Error with connection {$conn->resourceId}: {$e->getMessage()}\n";
    }
}

try {
    file_put_contents($GLOBALS['logFile'], date('Y-m-d H:i:s') . " - Setting up server on port 8080\n", FILE_APPEND);
    
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new SimpleEcho()
            )
        ),
        8080,
        '0.0.0.0' // Listen on all interfaces
    );

    file_put_contents($GLOBALS['logFile'], date('Y-m-d H:i:s') . " - Server created successfully\n", FILE_APPEND);
    echo "WebSocket server running on 0.0.0.0:8080\n";
    
    $server->run();
} catch (\Exception $e) {
    file_put_contents($GLOBALS['logFile'], date('Y-m-d H:i:s') . " - SERVER ERROR: {$e->getMessage()}\n{$e->getTraceAsString()}\n", FILE_APPEND);
    echo "SERVER ERROR: {$e->getMessage()}\n";
}