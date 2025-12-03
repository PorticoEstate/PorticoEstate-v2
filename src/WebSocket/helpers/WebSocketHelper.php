<?php

namespace App\WebSocket\helpers;

use App\WebSocket\WebSocketServer;

/**
 * Helper class for sending notifications to WebSocket clients
 */
class WebSocketHelper
{
    /**
     * Send a notification to all connected clients
     *
     * @param array|string $data Notification data
     * @return bool Success status
     */
    public static function sendNotification($data): bool
    {
        return WebSocketServer::sendRedisNotification($data);
    }
    
    /**
     * Send a notification to a specific session room
     *
     * @param string $sessionId Session identifier
     * @param array|string $data Notification data
     * @return bool Success status
     */
    public static function sendToSessionRoom(string $sessionId, $data): bool
    {
        // Create Redis data with session target
        $redisData = is_array($data) ? $data : json_decode($data, true);
        $redisData['target'] = 'session';
        $redisData['sessionId'] = $sessionId;
        
        return self::sendNotification($redisData);
    }
    
    /**
     * Send an entity event notification to all clients subscribed to an entity
     *
     * @param string $entityType Type of entity (resource, building, application, etc.)
     * @param int|string $entityId Entity identifier
     * @param string $eventType Type of event (create, update, delete, reservation, etc.)
     * @param array $data Additional event data
     * @return bool Success status
     */
    public static function sendEntityEvent(string $entityType, $entityId, string $eventType, array $data = []): bool
    {
        $message = [
            'type' => 'entity_event',
            'entityType' => $entityType,
            'entityId' => $entityId,
            'eventType' => $eventType,
            'data' => $data,
            'timestamp' => date('c'),
            'target' => 'entity'
        ];
        
        return self::sendNotification($message);
    }
}