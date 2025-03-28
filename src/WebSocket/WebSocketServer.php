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

    protected function broadcastNotification($msg)
    {
        foreach ($this->clients as $client) {
            $client->send($msg);
        }
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