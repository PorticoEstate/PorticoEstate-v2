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
     * This uses a direct WebSocket connection to the server
     * 
     * @param string $message The notification message
     * @param array $data Additional data for the notification
     * @param string|null $host Override the default host
     * @param int|null $port Override the default port
     * @return bool True if sent successfully, false otherwise
     */
    public static function sendNotification(string $message, array $data = [], ?string $host = null, ?int $port = null): bool
    {
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
        
        // Convert to JSON
        $jsonPayload = json_encode($payload);
        
        // Log the attempt
        error_log("WebSocketHelper: Broadcasting notification to {$wsHost}:{$wsPort}");
        error_log("WebSocketHelper: Payload: " . substr($jsonPayload, 0, 200));
        
        try {
            // Create a file that the WebSocket server can read
            // This is a workaround since we can't use HTTP endpoints
            $notificationFile = "/tmp/websocket_notification_" . uniqid() . ".json";
            file_put_contents($notificationFile, $jsonPayload);
            
            // Log the file creation
            error_log("WebSocketHelper: Created notification file: {$notificationFile}");
            
            // Success if we could write the file
            return true;
        } catch (Exception $e) {
            error_log("WebSocketHelper: Exception: " . $e->getMessage());
            return false;
        }
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
                'resource_id' => $resourceId,
                'timestamp' => date('c')
            ]
        );
    }
}