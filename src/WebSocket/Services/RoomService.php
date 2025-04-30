<?php

namespace App\WebSocket\Services;

use Ratchet\ConnectionInterface;
use Psr\Log\LoggerInterface;
use SplObjectStorage;

class RoomService
{
    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Rooms array: room_id => SplObjectStorage of connections
     *
     * @var array
     */
    private $rooms = [];

    /**
     * Connection to room mapping: connection => array of room_ids
     *
     * @var SplObjectStorage
     */
    private $connectionRooms;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->connectionRooms = new SplObjectStorage();
    }

    /**
     * Add a connection to a room
     *
     * @param string $roomId Room identifier
     * @param ConnectionInterface $conn Connection to add
     * @return bool Success status
     */
    public function joinRoom(string $roomId, ConnectionInterface $conn): bool
    {
        // Create room if it doesn't exist
        if (!isset($this->rooms[$roomId])) {
            $this->rooms[$roomId] = new SplObjectStorage();
            $this->logger->debug("Room created", [
                'roomId' => $roomId
            ]);
        }

        // Add connection to room
        $this->rooms[$roomId]->attach($conn);
        
        // Track which rooms this connection belongs to
        if (!$this->connectionRooms->contains($conn)) {
            $this->connectionRooms->attach($conn, []);
        }
        
        $connectionRooms = $this->connectionRooms[$conn];
        if (!in_array($roomId, $connectionRooms)) {
            $connectionRooms[] = $roomId;
            $this->connectionRooms[$conn] = $connectionRooms;
        }
        
        // Initialize activity timestamp when joining a room
        $this->updateConnectionActivity($roomId, $conn);
        
        $this->logger->debug("Client joined room", [
            'clientId' => $conn->resourceId,
            'roomId' => $roomId,
            'roomSize' => count($this->rooms[$roomId])
        ]);
        
        return true;
    }

    /**
     * Remove a connection from a room
     *
     * @param string $roomId Room identifier
     * @param ConnectionInterface $conn Connection to remove
     * @return bool Success status
     */
    public function leaveRoom(string $roomId, ConnectionInterface $conn): bool
    {
        if (isset($this->rooms[$roomId])) {
            // Remove connection from room
            $this->rooms[$roomId]->detach($conn);
            $clientId = $conn->resourceId;
            
            // Clean up activity records for this room
            if (isset($this->connectionActivityTimes[$clientId][$roomId])) {
                unset($this->connectionActivityTimes[$clientId][$roomId]);
                
                // Remove client entry if no more rooms
                if (empty($this->connectionActivityTimes[$clientId])) {
                    unset($this->connectionActivityTimes[$clientId]);
                }
            }
            
            // Update connection's room list
            if ($this->connectionRooms->contains($conn)) {
                $connectionRooms = $this->connectionRooms[$conn];
                $connectionRooms = array_filter($connectionRooms, function($id) use ($roomId) {
                    return $id !== $roomId;
                });
                $this->connectionRooms[$conn] = $connectionRooms;
            }
            
            $this->logger->debug("Client left room", [
                'clientId' => $clientId,
                'roomId' => $roomId,
                'roomSize' => count($this->rooms[$roomId])
            ]);
            
            // Clean up empty rooms
            if (count($this->rooms[$roomId]) === 0) {
                unset($this->rooms[$roomId]);
                $this->logger->debug("Room deleted", [
                    'roomId' => $roomId
                ]);
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * Remove a connection from all rooms it's in
     *
     * @param ConnectionInterface $conn Connection to remove
     * @return void
     */
    public function leaveAllRooms(ConnectionInterface $conn): void
    {
        if ($this->connectionRooms->contains($conn)) {
            $roomIds = $this->connectionRooms[$conn];
            $clientId = $conn->resourceId;
            
            foreach ($roomIds as $roomId) {
                $this->leaveRoom($roomId, $conn);
            }
            
            $this->connectionRooms->detach($conn);
            
            // Clean up activity timestamps for this connection
            if (isset($this->connectionActivityTimes[$clientId])) {
                unset($this->connectionActivityTimes[$clientId]);
            }
            
            $this->logger->debug("Client removed from all rooms", [
                'clientId' => $clientId,
                'roomCount' => count($roomIds)
            ]);
        }
    }

    /**
     * Broadcast a message to all connections in a room except the sender
     *
     * @param string $roomId Room identifier
     * @param ConnectionInterface $from Sender connection (will be excluded)
     * @param string $message Message to broadcast
     * @return int Number of clients the message was sent to
     */
    public function broadcastToRoom(string $roomId, ConnectionInterface $from, string $message): int
    {
        if (!isset($this->rooms[$roomId])) {
            return 0;
        }
        
        $room = $this->rooms[$roomId];
        $sentCount = 0;
        
        // Add timestamp to message if it's JSON and doesn't already have one
        if (is_string($message) && $message[0] === '{') {
            try {
                $data = json_decode($message, true);
                if ($data && !isset($data['timestamp'])) {
                    // Add timestamp at the top level for all message types
                    $data['timestamp'] = date('c'); // ISO 8601 format
                    $message = json_encode($data);
                }
            } catch (\Exception $e) {
                // If json decoding fails, continue with original message
            }
        }
        
        foreach ($room as $client) {
            if ($from !== $client) {
                $client->send($message);
                $sentCount++;
            }
        }
        
        if ($sentCount > 0) {
            $this->logger->info("Message broadcast to room", [
                'roomId' => $roomId,
                'recipients' => $sentCount,
                'senderClientId' => $from->resourceId
            ]);
        }
        
        return $sentCount;
    }

    /**
     * Send a message to all connections in a room including the sender
     *
     * @param string $roomId Room identifier
     * @param string $message Message to send
     * @return int Number of clients the message was sent to
     */
    public function sendToRoom(string $roomId, string $message): int
    {
        if (!isset($this->rooms[$roomId])) {
            return 0;
        }
        
        $room = $this->rooms[$roomId];
        $sentCount = 0;
        
        // Add timestamp to message if it's JSON and doesn't already have one
        if (is_string($message) && $message[0] === '{') {
            try {
                $data = json_decode($message, true);
                if ($data && !isset($data['timestamp'])) {
                    // Add timestamp at the top level for all message types
                    $data['timestamp'] = date('c'); // ISO 8601 format
                    $message = json_encode($data);
                }
            } catch (\Exception $e) {
                // If json decoding fails, continue with original message
            }
        }
        
        foreach ($room as $client) {
            $client->send($message);
            $sentCount++;
        }
        
        if ($sentCount > 0) {
            $this->logger->info("Message sent to room", [
                'roomId' => $roomId,
                'recipients' => $sentCount
            ]);
        }
        
        return $sentCount;
    }

    /**
     * Get all rooms a connection is in
     *
     * @param ConnectionInterface $conn Connection
     * @return array Room IDs
     */
    public function getConnectionRooms(ConnectionInterface $conn): array
    {
        if ($this->connectionRooms->contains($conn)) {
            return $this->connectionRooms[$conn];
        }
        
        return [];
    }

    /**
     * Get all connections in a room
     *
     * @param string $roomId Room identifier
     * @return SplObjectStorage|null Connections or null if room doesn't exist
     */
    public function getRoomConnections(string $roomId): ?SplObjectStorage
    {
        return $this->rooms[$roomId] ?? null;
    }

    /**
     * Get count of connections in a room
     *
     * @param string $roomId Room identifier
     * @return int Connection count
     */
    public function getRoomSize(string $roomId): int
    {
        return isset($this->rooms[$roomId]) ? count($this->rooms[$roomId]) : 0;
    }

    /**
     * Check if a room exists
     *
     * @param string $roomId Room identifier
     * @return bool True if room exists
     */
    public function roomExists(string $roomId): bool
    {
        return isset($this->rooms[$roomId]);
    }

    /**
     * Get count of all rooms
     *
     * @return int Room count
     */
    public function getRoomCount(): int
    {
        return count($this->rooms);
    }

    /**
     * Get all room IDs
     *
     * @return array Room IDs
     */
    public function getAllRoomIds(): array
    {
        return array_keys($this->rooms);
    }
    
    /**
     * Create a safe room ID from a session ID
     * 
     * @param string $sessionId Session ID
     * @return string Safe room ID
     */
    public function createRoomIdFromSession(string $sessionId): string
    {
        // Create a hashed room ID for security (we don't want to expose raw session IDs)
        return 'session_' . substr(md5($sessionId), 0, 10);
    }
    
    /**
     * Create a room ID for an entity (resource, building, application, etc.)
     * 
     * @param string $entityType Type of entity (resource, building, application, etc.)
     * @param int|string $entityId Entity ID
     * @return string Entity room ID
     */
    public function createRoomIdFromEntity(string $entityType, $entityId): string
    {
        return 'entity_' . $entityType . '_' . $entityId;
    }
    
    /**
     * Store for tracking connection activity times per room
     * Maps connection->resourceId => [roomId => timestamp]
     * 
     * @var array
     */
    private $connectionActivityTimes = [];
    
    /**
     * Store the last activity time for a connection in a room
     * 
     * @param string $roomId Room identifier
     * @param ConnectionInterface $conn Connection
     * @return void
     */
    public function updateConnectionActivity(string $roomId, ConnectionInterface $conn): void
    {
        $clientId = $conn->resourceId;
        
        // Initialize client entry if not exists
        if (!isset($this->connectionActivityTimes[$clientId])) {
            $this->connectionActivityTimes[$clientId] = [];
        }
        
        // Update timestamp for this room
        $this->connectionActivityTimes[$clientId][$roomId] = time();
        
        $this->logger->debug("Updated connection activity", [
            'clientId' => $clientId,
            'roomId' => $roomId,
            'timestamp' => date('c')
        ]);
    }
    
    /**
     * Send a ping to all connections in a room to check activity
     * 
     * @param string $roomId Room identifier
     * @return int Number of pings sent
     */
    public function pingRoom(string $roomId): int
    {
        if (!isset($this->rooms[$roomId])) {
            return 0;
        }
        
        $room = $this->rooms[$roomId];
        $sentCount = 0;
        
        $pingMessage = json_encode([
            'type' => 'room_ping',
            'roomId' => $roomId,
            'timestamp' => date('c')
        ]);
        
        foreach ($room as $client) {
            try {
                $client->send($pingMessage);
                $sentCount++;
            } catch (\Exception $e) {
                $this->logger->error("Room ping failure", [
                    'clientId' => $client->resourceId,
                    'roomId' => $roomId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        if ($sentCount > 0) {
            $this->logger->info("Room ping sent", [
                'roomId' => $roomId,
                'recipients' => $sentCount
            ]);
        }
        
        return $sentCount;
    }
    
    /**
     * Clean up inactive connections from rooms
     * Removes connections that haven't responded to pings for more than 8 minutes
     * Only cleans up entity rooms, not session rooms
     * 
     * @param int $inactiveThreshold Seconds of inactivity before removal (default: 480 = 8 minutes)
     * @return array Stats about cleanup
     */
    public function cleanupInactiveConnections(int $inactiveThreshold = 480): array
    {
        $now = time();
        $roomsCleaned = [];
        $connectionsRemoved = 0;
        
        foreach ($this->rooms as $roomId => $connections) {
            // Skip session rooms - they should only be cleaned up when a client disconnects
            if (strpos($roomId, 'session_') === 0) {
                continue;
            }
            
            $roomCleanCount = 0;
            
            foreach ($connections as $conn) {
                $clientId = $conn->resourceId;
                
                // Check if connection has recorded activity for this room
                $lastActivity = $this->connectionActivityTimes[$clientId][$roomId] ?? 0;
                $inactiveDuration = $now - $lastActivity;
                
                // If inactive for more than threshold, remove from room
                if ($lastActivity > 0 && $inactiveDuration > $inactiveThreshold) {
                    $this->leaveRoom($roomId, $conn);
                    $roomCleanCount++;
                    $connectionsRemoved++;
                    
                    // Clean up the activity record
                    if (isset($this->connectionActivityTimes[$clientId])) {
                        unset($this->connectionActivityTimes[$clientId][$roomId]);
                        
                        // Remove client entry if no more rooms
                        if (empty($this->connectionActivityTimes[$clientId])) {
                            unset($this->connectionActivityTimes[$clientId]);
                        }
                    }
                    
                    $this->logger->info("Removed inactive connection from room", [
                        'clientId' => $clientId,
                        'roomId' => $roomId,
                        'inactiveFor' => $inactiveDuration,
                        'threshold' => $inactiveThreshold
                    ]);
                }
            }
            
            if ($roomCleanCount > 0) {
                $roomsCleaned[$roomId] = $roomCleanCount;
            }
        }
        
        if ($connectionsRemoved > 0) {
            $this->logger->info("Cleanup summary", [
                'connectionsRemoved' => $connectionsRemoved,
                'roomsCleaned' => count($roomsCleaned),
                'timestamp' => date('c')
            ]);
        }
        
        return [
            'connectionsRemoved' => $connectionsRemoved,
            'roomsCleaned' => $roomsCleaned
        ];
    }
}