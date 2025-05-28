<?php

namespace App\modules\bookingfrontend\helpers;

use App\modules\phpgwapi\security\Sessions;
use Exception;
use App\WebSocket\Services\RedisService;

/**
 * Helper class for WebSocket operations in the bookingfrontend module
 */
class WebSocketHelper
{
    /**
     * WebSocket server host
     * Default is websocket service name for Docker or localhost for development
     *
     * @var string
     */
    protected static $host;

    /**
     * WebSocket server port
     *
     * @var int
     */
    protected static $port = 8080;

    /**
     * Redis host
     *
     * @var string
     */
    protected static $redisHost = null;

    /**
     * Redis port
     *
     * @var int
     */
    protected static $redisPort = 6379;

    /**
     * Redis notification channel
     *
     * @var string
     */
    protected static $redisChannel = 'notifications';

    /**
     * Redis session channel
     *
     * @var string
     */
    protected static $redisSessionChannel = 'session_messages';

    /**
     * Send a notification through the WebSocket server
     *
     * This uses Redis pub/sub with a file-based fallback
     *
     * @param string $message The notification message
     * @param array $data Additional data for the notification
     * @param string|null $host Override the default host
     * @param int|null $port Override the default port
     * @return bool True if sent successfully, false otherwise
     * @throws Exception If message is missing required fields
     */
    public static function sendNotification(string $message, array $data = [], ?string $host = null, ?int $port = null): bool
    {
        // Validate required fields
        if (!isset($data['type'])) {
            $errorMsg = "WebSocketHelper Error: Notification is missing required 'type' field";
            error_log($errorMsg);
            throw new Exception($errorMsg);
        }

        if (!isset($data['action'])) {
            $errorMsg = "WebSocketHelper Error: Notification is missing required 'action' field";
            error_log($errorMsg);
            throw new Exception($errorMsg);
        }

        // Prepare the message
        $payload = [
            'type' => 'notification',
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ];

        // Log the attempt
        error_log("WebSocketHelper: Broadcasting notification");
        error_log("WebSocketHelper: Payload: " . substr(json_encode($payload), 0, 200));

        // Use the RedisService to send notification (which includes fallback)
        return RedisService::sendNotification($payload, self::$redisChannel);
    }

    /**
     * Send a message to a specific session
     *
     * @param string $sessionId Session ID to send to
     * @param string $message Message to send
     * @param array $data Additional data
     * @return bool Success status
     * @throws Exception If message is missing required fields
     */
    public static function sendToSession(string $sessionId, string $message, array $data = []): bool
    {
        // Validate required fields for all data messages
        if (!isset($data['type'])) {
            $errorMsg = "WebSocketHelper Error: Message is missing required 'type' field";
            error_log($errorMsg);
            throw new Exception($errorMsg);
        }

        if (!isset($data['action'])) {
            $errorMsg = "WebSocketHelper Error: Message is missing required 'action' field";
            error_log($errorMsg);
            throw new Exception($errorMsg);
        }

        // Prepare the message
        $payload = [
            'type' => 'session_targeted',
            'sessionId' => $sessionId,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ];

        error_log("WebSocketHelper: Sending message to session: " . substr($sessionId, 0, 8) . "...");

        try {
            // Connect to Redis
            $redisHost = self::$redisHost ?? (getenv('REDIS_HOST') ?: 'redis');
            $redisPort = self::$redisPort ?? (getenv('REDIS_PORT') ?: 6379);

            // Use the Redis session messages channel
            return RedisService::sendNotification($payload, self::$redisSessionChannel);
        } catch (Exception $e) {
            error_log("WebSocketHelper: Failed to send session message: " . $e->getMessage());

            // Fall back to file method
            try {
                $timestamp = microtime(true);
                $filename = "/tmp/websocket_session_{$sessionId}_{$timestamp}.json";
                file_put_contents($filename, json_encode($payload));
                error_log("WebSocketHelper: Created session file: {$filename}");
                return true;
            } catch (Exception $e2) {
                error_log("WebSocketHelper: File fallback failed: " . $e2->getMessage());
                return false;
            }
        }
    }

    /**
     * Send a room message to a session group
     *
     * @param string $sessionId Session ID that defines the room
     * @param string $message Message to send
     * @param array $data Additional data
     * @return bool Success status
     * @throws Exception If message is missing required fields
     */
    public static function sendRoomMessage(string $sessionId, string $message, array $data = []): bool
    {
        // Make sure type and action are set
        $data['type'] = 'room_message';

        if (!isset($data['action'])) {
            $data['action'] = 'new'; // Default action if not specified
        }

        error_log("WebSocketHelper: Sending room message to session room: " . substr($sessionId, 0, 8) . "...");

        return self::sendToSession($sessionId, $message, $data);
    }

    /**
     * Send an update to users in a specific area/module
     *
     * @param string $area Area identifier (e.g. 'booking', 'application', 'resource')
     * @param string $action Action that occurred (e.g. 'new', 'changed', 'deleted')
     * @param string $sessionId Target session ID
     * @param array $data Data related to the update
     * @return bool Success status
     */
    public static function sendAreaUpdate(string $area, string $action, string $sessionId, array $data = []): bool
    {
        // Format message for area-specific update
        $message = "Update in {$area}";

        // Make sure action is one of the valid actions
        $validActions = ['new', 'changed', 'deleted'];
        if (!in_array($action, $validActions)) {
            $errorMsg = "WebSocketHelper Error: Invalid action '{$action}'. Must be one of: " . implode(', ', $validActions);
            error_log($errorMsg);
            throw new Exception($errorMsg);
        }

        // Prepare specialized data payload for area updates
        $updateData = array_merge([
            'type' => 'area_update',
            'area' => $area,
            'action' => $action,
            'timestamp' => date('c')
        ], $data);

        error_log("WebSocketHelper: Sending area update for {$area}/{$action} to session: " . substr($sessionId, 0, 8) . "...");

        return self::sendToSession($sessionId, $message, $updateData);
    }

    /**
     * Send a notification about a new partial application
     *
     * @param int $id Application ID
     * @param int|null $resourceId Resource ID
     * @return bool Success status
     */
    public static function notifyPartialApplicationCreated(int $id, ?int $resourceId = null): bool
    {
        return self::sendNotification(
            'New partial application created',
            [
                'id' => $id,
                'type' => 'partial_application_created',
                'action' => 'new',  // Action is now required
                'resource_id' => $resourceId,
                'timestamp' => date('c')
            ]
        );
    }

    /**
     * Send a notification about a new partial application asynchronously
     *
     * @param int $id Application ID
     * @param int|null $resourceId Resource ID
     * @return bool Success status of forking (not the notification itself)
     */
    public static function notifyPartialApplicationCreatedAsync(int $id, ?int $resourceId = null): bool
    {
        return self::forkNotification(function() use ($id, $resourceId) {
            self::notifyPartialApplicationCreated($id, $resourceId);
        });
    }

    /**
     * Broadcast an area update to all connected users
     * This is useful for global changes that affect all users
     *
     * @param string $area Area identifier (e.g. 'booking', 'application', 'resource')
     * @param string $action Action that occurred (e.g. 'new', 'changed', 'deleted')
     * @param array $data Additional data
     * @return bool Success status
     */
    public static function broadcastAreaUpdate(string $area, string $action, array $data = []): bool
    {
        $message = "Global update in {$area}";

        // Make sure action is one of the valid actions
        $validActions = ['new', 'changed', 'deleted'];
        if (!in_array($action, $validActions)) {
            $errorMsg = "WebSocketHelper Error: Invalid action '{$action}'. Must be one of: " . implode(', ', $validActions);
            error_log($errorMsg);
            throw new Exception($errorMsg);
        }

        $updateData = array_merge([
            'type' => 'area_update',
            'area' => $area,
            'action' => $action,
            'global' => true,
            'timestamp' => date('c')
        ], $data);

        error_log("WebSocketHelper: Broadcasting global area update for {$area}/{$action}");

        return self::sendNotification($message, $updateData);
    }

    /**
     * Send a notification to a specific entity room (building, resource, etc.)
     * This ensures the message is properly formatted for entity room routing
     *
     * @param string $entityType Type of entity (e.g. 'building', 'resource')
     * @param int|string $entityId ID of the entity
     * @param string $message Message description
     * @param string $action Action that occurred (e.g. 'updated', 'changed')
     * @param array $data Additional data to include
     * @return bool Success status
     */
    public static function sendEntityNotification(string $entityType, $entityId, string $message, string $action, array $data = []): bool
    {
        // Validate action
        $validActions = ['updated', 'created', 'deleted', 'changed', 'new'];
        if (!in_array($action, $validActions)) {
            $errorMsg = "WebSocketHelper Error: Invalid entity action '{$action}'. Must be one of: " . implode(', ', $validActions);
            error_log($errorMsg);
            throw new Exception($errorMsg);
        }

        // Create a room message for the entity using the room_message type
        // This uses the same room structure as subscriptions
        $roomId = 'entity_' . $entityType . '_' . $entityId;

        $payload = [
            'type' => 'room_message',  // Using room_message type instead of entity_event
            'roomId' => $roomId,       // Target the specific entity room
            'entityType' => $entityType, // Keep these for backward compatibility
            'entityId' => $entityId,
            'action' => $action,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ];

        error_log("WebSocketHelper: Sending room message to {$entityType} room for ID {$entityId}");

        // Send directly to Redis with a special room_messages channel for better message routing
        return self::sendRedisNotification($payload, 'room_messages');
    }

    /**
     * Send a notification to a specific entity room asynchronously using process forking
     *
     * @param string $entityType Type of entity (e.g. 'building', 'resource')
     * @param int|string $entityId ID of the entity
     * @param string $message Message description
     * @param string $action Action that occurred (e.g. 'updated', 'changed')
     * @param array $data Additional data to include
     * @return bool Success status of forking (not the notification itself)
     */
    public static function sendEntityNotificationAsync(string $entityType, $entityId, string $message, string $action, array $data = []): bool
    {
        return self::forkNotification(function() use ($entityType, $entityId, $message, $action, $data) {
            self::sendEntityNotification($entityType, $entityId, $message, $action, $data);
        });
    }

    /**
     * Fork a process to send WebSocket notifications asynchronously
     *
     * @param callable $callback The function to execute in the child process
     * @return bool True if forking was successful, false otherwise
     */
    public static function forkNotification(callable $callback): bool
    {
        // Check if pcntl is available
        if (!function_exists('pcntl_fork')) {
            error_log("pcntl_fork not available, running notification synchronously");
            $callback();
            return false;
        }

        // Fork the process
        $pid = pcntl_fork();

        // Fork failed
        if ($pid == -1) {
            error_log("Failed to fork process for WebSocket notification");
            $callback();
            return false;
        }

        // Parent process (return immediately)
        if ($pid) {
            return true;
        }

        // Child process
        try {
            // Run the callback function
            $callback();

            // Exit the child process
            if (function_exists('posix_kill')) {
                posix_kill(getmypid(), SIGTERM);
            }
            exit(0);
        } catch (Exception $e) {
            error_log("Error in forked WebSocket notification process: " . $e->getMessage());
            exit(1);
        }
    }

    /**
     * Helper method to send notification via Redis
     *
     * @param array $payload The message payload
     * @param string $channel The Redis channel (default: notifications)
     * @return bool Success status
     */
    public static function sendRedisNotification(array $payload, string $channel = 'notifications'): bool
    {
        return RedisService::sendNotification($payload, $channel);
    }

	/**
	 * Trigger an update of partial applications for a specific session
	 *
	 * Call this method whenever a partial application is created or updated
	 * to notify connected WebSocket clients without requiring a page refresh.
	 *
	 * @param string|null $sessionId The session ID to update or default to current session
	 * @return bool Success status
	 */
    public static function triggerPartialApplicationsUpdate(string $sessionId = null): bool
    {

		if(!isset($sessionId))
		{
			$session = Sessions::getInstance();
			$sessionId = $session->get_session_id();
		}
        error_log("WebSocketHelper: Triggering partial applications update for session: " . substr($sessionId, 0, 8) . "...");

        // Create the update message
        $updateMessage = [
            'type' => 'update_partial_applications',
            'sessionId' => $sessionId,
            'timestamp' => date('c')
        ];

        // Send to session_messages channel
        return self::sendRedisNotification($updateMessage, self::$redisSessionChannel);
    }

    /**
     * Asynchronously trigger an update of partial applications for a specific session
     *
     * @param string $sessionId The session ID to update
     * @return bool Success status of forking (not the notification itself)
     */
    public static function triggerPartialApplicationsUpdateAsync(string $sessionId): bool
    {
        return self::forkNotification(function() use ($sessionId) {
            self::triggerPartialApplicationsUpdate($sessionId);
        });
    }
}