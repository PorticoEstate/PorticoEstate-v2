<?php

namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Log\LoggerInterface;
use App\WebSocket\Services\RedisService;
use App\WebSocket\Services\NotificationService;
use App\WebSocket\Services\SessionService;
use App\WebSocket\Services\ConnectionService;
use App\WebSocket\Services\RoomService;
use App\WebSocket\Interfaces\WebSocketHandler;

class WebSocketServer implements MessageComponentInterface, WebSocketHandler
{
    private $logger;
    private $redisService;
    private $notificationService;
    private $sessionService;
    private $connectionService;
    private $roomService;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        
        // Initialize services
        $this->connectionService = new ConnectionService($logger);
        $this->redisService = new RedisService($logger);
        $this->sessionService = new SessionService($logger);
        $this->roomService = new RoomService($logger);
        $this->notificationService = new NotificationService(
            $logger, 
            $this->connectionService->getClients()
        );
    }

    /**
     * Handle new connections
     *
     * @param ConnectionInterface $conn
     * @return void
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        // Extract session data
        $this->sessionService->extractSessionData($conn);
        
        // Add connection
        $this->connectionService->addConnection($conn);
        
        // Check if booking session exists
        if (!isset($conn->bookingSessionId)) {
            $this->logger->warning("Client connected without booking session", [
                'clientId' => $conn->resourceId
            ]);
            
            // Send reconnect request
            $conn->send(json_encode([
                'type' => 'reconnect_required',
                'message' => 'Booking session not found. Please reconnect.',
                'code' => 'NO_BOOKING_SESSION',
                'timestamp' => date('c')
            ]));
            
            // We'll keep the connection but request the client to reconnect
            // This gives the client time to handle the message before reconnecting
        }
        
        // Add to a session-based room if session ID is available
        if (isset($conn->sessionId)) {
            $roomId = $this->roomService->createRoomIdFromSession($conn->sessionId);
            $this->roomService->joinRoom($roomId, $conn);
            
            // Store room ID in connection for easier access
            $conn->roomId = $roomId;
            
            $this->logger->info("Client added to session room", [
                'clientId' => $conn->resourceId,
                'roomId' => $roomId,
                'sessionType' => isset($conn->bookingSessionId) ? 'booking' : 'standard'
            ]);
        }
    }

    /**
     * Handle incoming messages
     *
     * @param ConnectionInterface $from
     * @param mixed $msg
     * @return void
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        $messageType = $data['type'] ?? 'unknown';
        
        // Enrich message with user context if available
        $enrichedData = $this->sessionService->enrichMessageWithUserContext($from, $data);
        
        // If data was enriched, re-encode the message
        if ($enrichedData !== $data) {
            $msg = json_encode($enrichedData);
            $data = $enrichedData;
        }
        
        // Log the message
        $logContext = $this->sessionService->getSessionLogContext($from, $messageType);
        $logContext['recipients'] = $this->connectionService->getClientCount() - 1;
        $logContext['contentLength'] = strlen($msg);
        
        $this->logger->info("Message received", $logContext);
        
        // Handle entity subscription
        if ($messageType === 'subscribe' && isset($data['entityType']) && isset($data['entityId'])) {
            $entityType = $data['entityType'];
            $entityId = $data['entityId'];
            $roomId = $this->roomService->createRoomIdFromEntity($entityType, $entityId);
            
            // Join the entity room
            $this->roomService->joinRoom($roomId, $from);
            
            $this->logger->info("Client subscribed to entity", [
                'clientId' => $from->resourceId,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'roomId' => $roomId
            ]);
            
            // Send confirmation to the client
            $from->send(json_encode([
                'type' => 'subscription_confirmation',
                'entityType' => $entityType,
                'entityId' => $entityId,
                'status' => 'subscribed',
                'timestamp' => date('c')
            ]));
            return;
        }
        
        // Handle entity unsubscription
        if ($messageType === 'unsubscribe' && isset($data['entityType']) && isset($data['entityId'])) {
            $entityType = $data['entityType'];
            $entityId = $data['entityId'];
            $roomId = $this->roomService->createRoomIdFromEntity($entityType, $entityId);
            
            // Leave the entity room
            $this->roomService->leaveRoom($roomId, $from);
            
            $this->logger->info("Client unsubscribed from entity", [
                'clientId' => $from->resourceId,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'roomId' => $roomId
            ]);
            
            // Send confirmation to the client
            $from->send(json_encode([
                'type' => 'subscription_confirmation',
                'entityType' => $entityType,
                'entityId' => $entityId,
                'status' => 'unsubscribed',
                'timestamp' => date('c')
            ]));
            return;
        }
        
        // Check if this message is intended for a specific room
        if ($messageType === 'room_message' && isset($data['roomId'])) {
            $roomId = $data['roomId'];
            
            // Check if client is in the specified room
            $clientRooms = $this->roomService->getConnectionRooms($from);
            
            if (in_array($roomId, $clientRooms)) {
                // Send to all clients in the room except sender
                $this->roomService->broadcastToRoom($roomId, $from, $msg);
                
                $this->logger->info("Room message sent", [
                    'clientId' => $from->resourceId,
                    'roomId' => $roomId
                ]);
                return;
            } else {
                // Client tried to send to a room they're not in
                $this->logger->warning("Unauthorized room message", [
                    'clientId' => $from->resourceId,
                    'roomId' => $roomId
                ]);
                
                // Send an error response to the client
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Not authorized to send to this room',
                    'code' => 'ROOM_ACCESS_DENIED',
                    'timestamp' => date('c')
                ]));
                return;
            }
        }
        
        // Handle session-specific messages
        if ($messageType === 'session_message' && isset($from->roomId)) {
            $roomId = $from->roomId;
            
            // Override the message type for consistency in logs
            $data['type'] = 'room_message';
            $data['roomId'] = $roomId;
            $msg = json_encode($data);
            
            // Send to all clients in the session room except sender
            $this->roomService->broadcastToRoom($roomId, $from, $msg);
            
            $this->logger->info("Session message sent", [
                'clientId' => $from->resourceId,
                'roomId' => $roomId,
                'sessionType' => isset($from->bookingSessionId) ? 'booking' : 'standard'
            ]);
            return;
        }
        
        // Process standard message types
        $this->notificationService->processMessage($from, $msg);
    }

    /**
     * Handle closed connections
     *
     * @param ConnectionInterface $conn
     * @return void
     */
    public function onClose(ConnectionInterface $conn): void
    {
        // If connection was in rooms, handle room departure
        if (isset($conn->roomId)) {
            // Simply remove from all rooms without notifications
            $this->roomService->leaveAllRooms($conn);
        }
        
        // Remove connection from the server
        $this->connectionService->removeConnection($conn);
    }

    /**
     * Handle connection errors
     *
     * @param ConnectionInterface $conn
     * @param \Exception $e
     * @return void
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->connectionService->handleError($conn, $e);
    }

    /**
     * Check for notification files
     *
     * @return void
     */
    public function checkNotificationFiles(): void
    {
        $this->notificationService->checkNotificationFiles();
    }

    /**
     * Send a server ping to all connected clients
     * This happens every 4 minutes to keep connections alive
     * The client should respond with a pong message
     *
     * @return void
     */
    public function sendServerPing(): void
    {
        $this->notificationService->sendServerPing();
    }

    /**
     * Broadcast a notification to all connected clients
     *
     * @param mixed $msg
     * @return void
     */
    public function broadcastNotification($msg): void
    {
        $this->notificationService->broadcastNotification($msg);
    }
    
    /**
     * Send a notification to a specific session room
     *
     * @param string $sessionId Session identifier
     * @param array|string $msg The message to send
     * @return bool Success status
     */
    public function sendToSessionRoom(string $sessionId, $msg): bool
    {
        // Create room ID from session
        $roomId = $this->roomService->createRoomIdFromSession($sessionId);
        
        // Check if room exists
        if (!$this->roomService->roomExists($roomId)) {
            $this->logger->info("Session room not found", [
                'roomId' => $roomId,
                'sessionId' => substr($sessionId, 0, 8) . '...' // Log only part of session ID for security
            ]);
            return false;
        }
        
        // Convert message to JSON if it's an array
        if (is_array($msg)) {
            // Don't add roomId for server_message type to keep it clean for client
            if ($msg['type'] !== 'server_message' && !isset($msg['roomId'])) {
                $msg['roomId'] = $roomId;
            }
            
            $msg = json_encode($msg);
        } else if (is_string($msg) && $msg[0] === '{') {
            // If it's already a JSON string, try to parse it to check for server_message
            try {
                $data = json_decode($msg, true);
                if ($data && isset($data['type']) && $data['type'] !== 'server_message' && !isset($data['roomId'])) {
                    $data['roomId'] = $roomId;
                    $msg = json_encode($data);
                }
            } catch (\Exception $e) {
                // If parsing fails, continue with the original message
            }
        }
        
        // Send to room
        $sent = $this->roomService->sendToRoom($roomId, $msg);
        
        return $sent > 0;
    }
    
    /**
     * Send a notification to a specific entity room
     *
     * @param string $entityType Type of entity (resource, building, application, etc.)
     * @param int|string $entityId Entity identifier
     * @param array|string $msg The message to send
     * @return bool Success status
     */
    public function sendToEntityRoom(string $entityType, $entityId, $msg): bool
    {
        // Create room ID from entity
        $roomId = $this->roomService->createRoomIdFromEntity($entityType, $entityId);
        
        // Check if room exists
        if (!$this->roomService->roomExists($roomId)) {
            $this->logger->info("Entity room not found", [
                'roomId' => $roomId,
                'entityType' => $entityType,
                'entityId' => $entityId
            ]);
            return false;
        }
        
        // Convert message to JSON if it's an array
        if (is_array($msg)) {
            // Add entity info to the message
            if (!isset($msg['entityType'])) {
                $msg['entityType'] = $entityType;
            }
            if (!isset($msg['entityId'])) {
                $msg['entityId'] = $entityId;
            }
            if (!isset($msg['roomId'])) {
                $msg['roomId'] = $roomId;
            }
            
            $msg = json_encode($msg);
        } else if (is_string($msg) && $msg[0] === '{') {
            // If it's already a JSON string, try to parse it to add entity info
            try {
                $data = json_decode($msg, true);
                if ($data) {
                    if (!isset($data['entityType'])) {
                        $data['entityType'] = $entityType;
                    }
                    if (!isset($data['entityId'])) {
                        $data['entityId'] = $entityId;
                    }
                    if (!isset($data['roomId'])) {
                        $data['roomId'] = $roomId;
                    }
                    $msg = json_encode($data);
                }
            } catch (\Exception $e) {
                // If parsing fails, continue with the original message
            }
        }
        
        // Send to room
        $sent = $this->roomService->sendToRoom($roomId, $msg);
        
        return $sent > 0;
    }

    /**
     * Get the number of connected clients
     *
     * @return int
     */
    public function getClientCount(): int
    {
        return $this->connectionService->getClientCount();
    }

    /**
     * Check if Redis is enabled
     *
     * @return bool
     */
    public function isRedisEnabled(): bool
    {
        return $this->redisService->isEnabled();
    }

    /**
     * Send a notification using Redis pub/sub
     * Can be called statically from other parts of the application
     * 
     * @param array|string $data Notification data to send
     * @param string $channel Redis channel to use (default: notifications)
     * @return bool Success status
     */
    public static function sendRedisNotification($data, string $channel = 'notifications'): bool
    {
        return RedisService::sendNotification($data, $channel);
    }
    
    /**
     * Get all rooms in the system
     *
     * @return array Room information
     */
    public function getRooms(): array
    {
        $rooms = [];
        $roomIds = $this->roomService->getAllRoomIds();
        
        foreach ($roomIds as $roomId) {
            $rooms[] = [
                'id' => $roomId,
                'clients' => $this->roomService->getRoomSize($roomId),
                'isSessionRoom' => strpos($roomId, 'session_') === 0
            ];
        }
        
        return $rooms;
    }
}