<?php

namespace App\WebSocket\Interfaces;

use Ratchet\ConnectionInterface;

interface WebSocketHandler
{
    /**
     * Handle new connections
     *
     * @param ConnectionInterface $conn
     * @return void
     */
    public function onOpen(ConnectionInterface $conn);
    
    /**
     * Handle incoming messages
     *
     * @param ConnectionInterface $from
     * @param mixed $msg
     * @return void
     */
    public function onMessage(ConnectionInterface $from, $msg);
    
    /**
     * Handle closed connections
     *
     * @param ConnectionInterface $conn
     * @return void
     */
    public function onClose(ConnectionInterface $conn);
    
    /**
     * Handle connection errors
     *
     * @param ConnectionInterface $conn
     * @param \Exception $e
     * @return void
     */
    public function onError(ConnectionInterface $conn, \Exception $e);
    
    /**
     * Check for notification files
     *
     * @return void
     */
    public function checkNotificationFiles();
    
    /**
     * Send a server ping to all connected clients
     *
     * @return void
     */
    public function sendServerPing();
    
    /**
     * Broadcast a notification to all connected clients
     *
     * @param mixed $msg
     * @return void
     */
    public function broadcastNotification($msg);
    
    /**
     * Send a notification to a specific session room
     *
     * @param string $sessionId
     * @param mixed $msg
     * @return bool Success status
     */
    public function sendToSessionRoom(string $sessionId, $msg);
    
    /**
     * Get the number of connected clients
     *
     * @return int
     */
    public function getClientCount();
    
    /**
     * Check if Redis is enabled
     *
     * @return bool
     */
    public function isRedisEnabled();
    
    /**
     * Get all rooms in the system
     *
     * @return array Room information
     */
    public function getRooms();
}