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
use App\WebSocket\Services\DatabaseService;
use App\WebSocket\Interfaces\WebSocketHandler;

class WebSocketServer implements MessageComponentInterface, WebSocketHandler
{
    private $logger;
    private $redisService;
    private $notificationService;
    private $sessionService;
    private $connectionService;
    private $roomService;
    private $databaseService;

    /**
     * Get the roomService
     *
     * @return RoomService
     */
    public function getRoomService(): RoomService
    {
        return $this->roomService;
    }

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        // Initialize services
        $this->connectionService = new ConnectionService($logger);
        $this->redisService = new RedisService($logger);
        $this->sessionService = new SessionService($logger);
        $this->roomService = new RoomService($logger);
        $this->databaseService = new DatabaseService($logger);
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
        // Store connection time for duration tracking
        $conn->connectTime = time();

        // Extract session data
        $this->sessionService->extractSessionData($conn);

        // Log detailed connection information including browser details
        $this->logger->notice("New connection!", [
            'clientId' => $conn->resourceId,
            'ipAddress' => $conn->remoteAddress ?? 'unknown',
            'totalClients' => $this->connectionService->getClientCount() + 1, // +1 for this new connection
            'hasSession' => isset($conn->sessionId),
            'hasBookingSession' => isset($conn->bookingSessionId),
            'hasUserInfo' => isset($conn->userInfo),
            'browser' => isset($conn->userAgent) ? $conn->userAgent : 'unknown'
        ]);

        // Add connection
        $this->connectionService->addConnection($conn);

        // Check if session ID is required
        if (isset($conn->sessionIdRequired) && $conn->sessionIdRequired) {
            $this->logger->info("Client connected without session - requesting session ID", [
                'clientId' => $conn->resourceId,
                'browser' => isset($conn->userAgent) ? $conn->userAgent : 'unknown'
            ]);

            // Send session request - ask client to provide session ID
            $conn->send(json_encode([
                'type' => 'session_id_required',
                'message' => 'Please provide your session ID via an update_session message',
                'code' => 'NO_SESSION',
                'timestamp' => date('c')
            ]));

            // We'll keep the connection and wait for the client to send their session ID
            // This allows the client to fetch their session ID and update it without disconnecting
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

            // Send message to user about being added to the room
            $conn->send(json_encode([
                'type' => 'room_joined',
                'roomId' => $roomId,
                'roomType' => 'session',
                'message' => 'You have been added to a session room',
                'roomSize' => $this->roomService->getRoomSize($roomId),
                'timestamp' => date('c')
            ]));

            // Get current room information for the connection
            $connectionRooms = $this->roomService->getConnectionRooms($conn);
            $roomsInfo = [];
            foreach ($connectionRooms as $roomId) {
                $roomsInfo[] = [
                    'id' => $roomId,
                    'size' => $this->roomService->getRoomSize($roomId),
                    'type' => strpos($roomId, 'session_') === 0 ? 'session' : 'entity'
                ];
            }

            // Send connection success message to client with environment variables and rooms info
            $conn->send(json_encode([
                'type' => 'connection_success',
                'message' => 'Successfully connected to WebSocket server',
                'roomId' => $roomId,
                'timestamp' => date('c'),
                'rooms' => $roomsInfo,
                'environment' => [
                    'NEXTJS_HOST' => getenv('NEXTJS_HOST') ?: null,
                    'SLIM_HOST' => getenv('SLIM_HOST') ?: null,
                    'REDIS_HOST' => getenv('REDIS_HOST') ?: null,
                    'websocket_host' => getenv('websocket_host') ?: getenv('WEBSOCKET_HOST') ?: null
                ]
            ]));
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

            // Send message to user about being added to the room
            $from->send(json_encode([
                'type' => 'room_joined',
                'roomId' => $roomId,
                'roomType' => 'entity',
                'entityType' => $entityType,
                'entityId' => $entityId,
                'message' => 'You have been added to an entity room',
                'roomSize' => $this->roomService->getRoomSize($roomId),
                'timestamp' => date('c')
            ]));

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

        // Handle entity_event messages
        if ($messageType === 'entity_event' && isset($data['entityType']) && isset($data['entityId'])) {
            $entityType = $data['entityType'];
            $entityId = $data['entityId'];
            $roomId = $this->roomService->createRoomIdFromEntity($entityType, $entityId);

            // Check if entity room exists
            if ($this->roomService->roomExists($roomId)) {
                $this->logger->info("Entity event message routing to room", [
                    'clientId' => $from->resourceId,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'roomId' => $roomId
                ]);

                // Broadcast to entity room
                $this->roomService->broadcastToRoom($roomId, $from, $msg);
                return;
            } else {
                $this->logger->warning("Entity event for non-existent room", [
                    'clientId' => $from->resourceId,
                    'entityType' => $entityType,
                    'entityId' => $entityId
                ]);
            }
        }

        // Handle session ID update
        if ($messageType === 'update_session' && isset($data['sessionId'])) {
            $sessionId = $data['sessionId'];

            // Validate sessionId - it should be a non-empty string
            if (empty($sessionId) || !is_string($sessionId)) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Invalid session ID provided',
                    'code' => 'INVALID_SESSION_ID',
                    'timestamp' => date('c')
                ]));
                return;
            }

            // Update the session ID
            $result = $this->sessionService->updateSessionId($from, $sessionId, $this->roomService);

            // Send room_joined notification if a new room was joined
            if ($result['success'] && isset($result['roomJoined']) && $result['roomJoined']) {
                $from->send(json_encode([
                    'type' => 'room_joined',
                    'roomId' => $result['roomId'],
                    'roomType' => 'session',
                    'message' => 'You have been added to a session room',
                    'roomSize' => $result['roomSize'],
                    'timestamp' => date('c')
                ]));
            }

            // Check if this was an initial session setup (for better messaging)
            $wasInitialSetup = isset($from->sessionIdRequired) && $from->sessionIdRequired;

            // Get current room information for the connection
            $connectionRooms = $this->roomService->getConnectionRooms($from);
            $roomsInfo = [];
            foreach ($connectionRooms as $roomId) {
                $roomsInfo[] = [
                    'id' => $roomId,
                    'size' => $this->roomService->getRoomSize($roomId),
                    'type' => strpos($roomId, 'session_') === 0 ? 'session' : 'entity'
                ];
            }

            // Send a confirmation message with environment variables and rooms info
            $from->send(json_encode([
                'type' => 'session_update_confirmation',
                'success' => $result['success'],
                'message' => $wasInitialSetup ? 'Session ID set successfully' : $result['message'],
                'action' => $result['action'],
                'wasRequired' => $wasInitialSetup,
                'sessionId' => substr($sessionId, 0, 8) . '...',  // Only show part of the session ID for security
                'timestamp' => date('c'),
                'rooms' => $roomsInfo,
                'environment' => [
                    'NEXTJS_HOST' => getenv('NEXTJS_HOST') ?: null,
                    'SLIM_HOST' => getenv('SLIM_HOST') ?: null,
                    'REDIS_HOST' => getenv('REDIS_HOST') ?: null,
                    'websocket_host' => getenv('websocket_host') ?: getenv('WEBSOCKET_HOST') ?: null
                ]
            ]));

            $this->logger->info("Client session updated", [
                'clientId' => $from->resourceId,
                'action' => $result['action'],
                'result' => $result['success'] ? 'success' : 'failure'
            ]);
            return;
        }

        // Handle room ping responses
        if ($messageType === 'room_ping_response' && isset($data['roomId'])) {
            $roomId = $data['roomId'];

            // Update the connection's activity timestamp for this room
            $this->roomService->updateConnectionActivity($roomId, $from);

            $this->logger->debug("Room ping response received", [
                'clientId' => $from->resourceId,
                'roomId' => $roomId,
                'pingId' => $data['pingId'] ?? 'unknown'
            ]);
            return;
        }

        // Handle pong responses from client
        if ($messageType === 'pong') {
            // Calculate round-trip time if client_timestamp is provided
            $clientTime = $data['client_timestamp'] ?? null;
            $rtt = null;

            if ($clientTime) {
                $rtt = time() * 1000 - $clientTime; // in milliseconds
            }

            // Log pong with detailed information
            $this->logger->info("Ping-Pong", [
                'clientId' => $from->resourceId,
                'action' => 'pong received',
                'pongId' => $data['id'] ?? 'unknown',
                'replyTo' => $data['reply_to'] ?? 'unknown',
                'sessionActive' => isset($from->sessionId),
                'browser' => isset($from->userAgent) ? $from->userAgent : 'unknown',
                'rtt' => $rtt !== null ? $rtt . 'ms' : 'unknown'
            ]);

            return;
        }

        // Handle user info update
        if ($messageType === 'update_user_info' && isset($data['userId'])) {
            $userId = (int)$data['userId'];

            // Create or update the userInfo object with the provided user ID
            if (!isset($from->userInfo)) {
                $from->userInfo = [
                    'sessionFound' => true,
                    'sessionId' => isset($from->sessionId) ? substr($from->sessionId, 0, 8) . '****' : null,
                    'sessionType' => isset($from->bookingSessionId) ? 'booking' : 'standard'
                ];
            }

            // Update the user ID
            $from->userInfo['userId'] = $userId;

            // Log the user info update
            $this->logger->info("User info updated", [
                'clientId' => $from->resourceId,
                'userId' => $userId,
                'sessionType' => isset($from->bookingSessionId) ? 'booking' : 'standard'
            ]);

            // Send a confirmation back to the client
            $from->send(json_encode([
                'type' => 'user_info_update_confirmation',
                'success' => true,
                'message' => 'User information updated successfully',
                'userId' => $userId,
                'timestamp' => date('c')
            ]));

            return;
        }

        // Handle requests for partial applications
        if ($messageType === 'get_partial_applications') {
            $this->logger->info("Request for partial applications", [
                'clientId' => $from->resourceId,
                'sessionType' => isset($from->bookingSessionId) ? 'booking' : 'standard',
                'hasUserInfo' => isset($from->userInfo) && isset($from->userInfo['userId']),
                'timestamp' => date('c')
            ]);

            // Get partial applications for the user
            $response = $this->getUserPartialApplications($from);

            // Send the response back to the client
            $from->send(json_encode([
                'type' => 'partial_applications_response',
                'data' => $response,
                'timestamp' => date('c')
            ]));

            return;
        }

        // Process other standard message types
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
        // Log a more concise connection closed message first
        $this->logger->info("Connection closed", [
            'clientId' => $conn->resourceId,
            'ipAddress' => $conn->remoteAddress ?? 'unknown',
            'remainingClients' => $this->connectionService->getClientCount() - 1 // -1 for this connection being removed
        ]);

        // Collect detailed information about the connection for logging
        $clientInfo = [
            'clientId' => $conn->resourceId,
            'remoteAddress' => $conn->remoteAddress ?? 'unknown',
            'browser' => isset($conn->userAgent) ? $conn->userAgent : 'unknown',
            'httpHeaders' => isset($conn->httpRequest) ? 'available' : 'none',
            'closeCode' => property_exists($conn, 'closeCode') ? $conn->closeCode : 'unknown',
            'closeReason' => property_exists($conn, 'closeReason') ? $conn->closeReason : 'unknown',
            'sessionId' => isset($conn->sessionId) ? substr($conn->sessionId, 0, 8) . '...' : 'none',
            'wasInRooms' => isset($conn->roomId),
            'roomCount' => $this->roomService->getConnectionRooms($conn) ? count($this->roomService->getConnectionRooms($conn)) : 0,
            'connectionDuration' => isset($conn->connectTime) ? (time() - $conn->connectTime) . ' seconds' : 'unknown'
        ];

        $this->logger->debug("Connection closing with details", $clientInfo);

        // If connection was in rooms, handle room departure
        if (isset($conn->roomId) || $clientInfo['roomCount'] > 0) {
            $roomsInfo = $this->roomService->getConnectionRooms($conn);
            $this->logger->info("Leaving rooms", [
                'clientId' => $conn->resourceId,
                'rooms' => $roomsInfo
            ]);

            // Remove from all rooms
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
        // Log additional context about the connection state
        $clientInfo = [
            'clientId' => $conn->resourceId,
            'remoteAddress' => $conn->remoteAddress ?? 'unknown',
            'sessionActive' => isset($conn->sessionId),
            'hasBookingSession' => isset($conn->bookingSessionId),
            'wasInRooms' => $this->roomService->getConnectionRooms($conn) ? count($this->roomService->getConnectionRooms($conn)) > 0 : false,
            'errorMessage' => $e->getMessage(),
            'errorCode' => $e->getCode(),
            'errorFile' => $e->getFile(),
            'errorLine' => $e->getLine(),
            'errorTrace' => array_slice($e->getTrace(), 0, 3)  // First 3 frames of stack trace
        ];

        $this->logger->error("WebSocket connection error with details", $clientInfo);

        // Let the connection service handle the error (which includes closing the connection)
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
     * Check if database is connected
     *
     * @return bool
     */
    public function isDatabaseConnected(): bool
    {
        return $this->databaseService->isConnected();
    }

    /**
    /**
     * Get user's partial applications
     *
     * @param ConnectionInterface $conn The WebSocket connection
     * @return array List of partial applications or error message
     */
    public function getUserPartialApplications(ConnectionInterface $conn): array
    {
        // Check if database is connected
        if (!$this->isDatabaseConnected()) {
            return [
                'error' => true,
                'message' => 'Database not connected',
                'status' => 'error'
            ];
        }

        // Check if the connection has a session ID
        if (!isset($conn->sessionId)) {
            return [
                'error' => true,
                'message' => 'No session found',
                'status' => 'error'
            ];
        }

        try {
            // Get the session ID directly from the connection
            $sessionId = $conn->sessionId;

            $this->logger->info("Retrieving partial applications for session", [
                'sessionId' => substr($sessionId, 0, 8) . '...'
            ]);

            // Get partial applications directly using the session ID
            // This now uses ApplicationRepository directly from the Slim codebase
            $applications = $this->databaseService->getPartialApplicationsBySessionId($sessionId);

            return [
                'error' => false,
                'status' => 'success',
                'applications' => $applications,
                'count' => count($applications),
                'sessionId' => substr($sessionId, 0, 8) . '...' // Include session ID in response for debugging
            ];
        } catch (\Exception $e) {
            $this->logger->error("Error getting user partial applications", [
                'error' => $e->getMessage(),
                'sessionId' => isset($conn->sessionId) ? substr($conn->sessionId, 0, 8) . '...' : 'none'
            ]);

            return [
                'error' => true,
                'message' => 'Error processing request: ' . $e->getMessage(),
                'status' => 'error'
            ];
        }
    }    /**
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
     * Fetch and send partial applications update to a specific session
     *
     * @param string $sessionId The session ID
     * @return bool Success status
     */
    public function sendPartialApplicationsUpdate(string $sessionId): bool
    {
        // Check if database is connected
        if (!$this->isDatabaseConnected()) {
            $this->logger->error("Cannot send partial applications update - database not connected", [
                'sessionId' => substr($sessionId, 0, 8) . '...'
            ]);
            return false;
        }

        try {
            $this->logger->info("Retrieving partial applications for session update", [
                'sessionId' => substr($sessionId, 0, 8) . '...'
            ]);

            // Get applications using session ID (now using ApplicationRepository directly)
            $applications = $this->databaseService->getPartialApplicationsBySessionId($sessionId);

            // Prepare the response data
            $responseData = [
                'type' => 'partial_applications_response',
                'data' => [
                    'error' => false,
                    'status' => 'success',
                    'applications' => $applications,
                    'count' => count($applications),
                    'sessionId' => substr($sessionId, 0, 8) . '...',
                    'source' => 'server_push',
                    'timestamp' => date('c')
                ],
                'timestamp' => date('c')
            ];

            // Send to session room
            $success = $this->sendToSessionRoom($sessionId, $responseData);

            $this->logger->info("Partial applications update sent", [
                'sessionId' => substr($sessionId, 0, 8) . '...',
                'applicationCount' => count($applications),
                'success' => $success
            ]);

            return $success;
        } catch (\Exception $e) {
            $this->logger->error("Error sending partial applications update", [
                'sessionId' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);

            return false;
        }
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