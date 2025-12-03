<?php

/**
 * Helper script to send a notification through WebSocket server
 * 
 * This can be used from other parts of the application to send real-time
 * notifications to connected clients.
 */

use App\WebSocket\WebSocketServer;

// Make sure we have access to the autoloader
$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    throw new \RuntimeException("Composer autoload not found at: {$autoloadPath}. Please run composer install.");
}
require_once $autoloadPath;

/**
 * Helper function to send notifications with fallback methods
 * 
 * @param string $message Main notification message
 * @param array $data Additional notification data
 * @param string $type Notification type
 * @param string $host Redis host
 * @param int $port Redis port
 * @return bool Success status
 */
function sendWebSocketNotification(
    string $message, 
    array $data = [], 
    string $type = 'notification',
    string $host = null,
    int $port = null
): bool {
    // Set default notification structure
    $notification = [
        'type' => $type,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('c')
    ];
    
    // First try Redis method
    try {
        if (WebSocketServer::sendRedisNotification($notification)) {
            return true;
        }
    } catch (\Throwable $e) {
        // Swallow the exception and try file method
        error_log("Redis notification failed: " . $e->getMessage());
    }
    
    // File-based fallback
    try {
        $timestamp = microtime(true);
        $filename = "/tmp/websocket_notification_{$timestamp}.json";
        $success = file_put_contents($filename, json_encode($notification));
        return $success !== false;
    } catch (\Throwable $e) {
        error_log("File-based notification failed: " . $e->getMessage());
        return false;
    }
}

// Command-line usage example
if (PHP_SAPI === 'cli' && isset($argv) && basename($argv[0]) === basename(__FILE__)) {
    if (count($argv) < 2) {
        echo "Usage: php send_notification.php <message> [type] [data_json]\n";
        echo "Example: php send_notification.php \"New booking created\" booking '{\"id\":123}'\n";
        exit(1);
    }
    
    $message = $argv[1];
    $type = $argv[2] ?? 'notification';
    $data = isset($argv[3]) ? json_decode($argv[3], true) : [];
    
    if (json_last_error() !== JSON_ERROR_NONE && isset($argv[3])) {
        echo "Error: Invalid JSON data\n";
        exit(1);
    }
    
    $result = sendWebSocketNotification($message, $data, $type);
    echo $result ? "Notification sent successfully\n" : "Failed to send notification\n";
}