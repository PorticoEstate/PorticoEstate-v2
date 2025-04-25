<?php

namespace App\modules\bookingfrontend\helpers;

use Exception;
use App\WebSocket\Services\RedisService;

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
        
        // Use the provided host/port or defaults
        $wsHost = $host ?? self::$host;
        $wsPort = $port ?? self::$port;
        
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
}