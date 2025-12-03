<?php

namespace App\WebSocket\Services;

use Psr\Log\LoggerInterface;
use Ratchet\ConnectionInterface;
use SplObjectStorage;

class ConnectionService
{
    private $logger;
    private $clients;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->clients = new \SplObjectStorage;
    }

    /**
     * Add a new connection
     *
     * @param ConnectionInterface $conn The connection to add
     * @return void
     */
    public function addConnection(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $ipAddress = $conn->remoteAddress ?? 'unknown';
        
        $this->logger->info("New connection!", [
            'clientId' => $conn->resourceId,
            'ipAddress' => $ipAddress,
            'totalClients' => count($this->clients),
            'hasSession' => !empty($conn->sessionId ?? null),
            'hasBookingSession' => !empty($conn->bookingSessionId ?? null),
            'hasUserInfo' => !empty($conn->userInfo ?? null)
        ]);
    }

    /**
     * Remove a connection
     *
     * @param ConnectionInterface $conn The connection to remove
     * @return void
     */
    public function removeConnection(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
        $ipAddress = $conn->remoteAddress ?? 'unknown';
        
        $this->logger->info("Connection closed", [
            'clientId' => $conn->resourceId,
            'ipAddress' => $ipAddress,
            'remainingClients' => count($this->clients)
        ]);
    }

    /**
     * Handle connection error
     *
     * @param ConnectionInterface $conn The connection with error
     * @param \Exception $e The exception that occurred
     * @return void
     */
    public function handleError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->logger->error("Connection error", [
            'clientId' => $conn->resourceId,
            'ipAddress' => $conn->remoteAddress ?? 'unknown',
            'errorMessage' => $e->getMessage(),
            'errorCode' => $e->getCode(),
            'errorFile' => $e->getFile(),
            'errorLine' => $e->getLine()
        ]);
        
        $conn->close();
    }

    /**
     * Get the client storage
     *
     * @return SplObjectStorage
     */
    public function getClients(): SplObjectStorage
    {
        return $this->clients;
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
}