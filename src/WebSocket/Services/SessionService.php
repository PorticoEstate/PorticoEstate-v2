<?php

namespace App\WebSocket\Services;

use Psr\Log\LoggerInterface;
use Ratchet\ConnectionInterface;

class SessionService
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Extract session data from connection and attach it to the connection object
     *
     * @param ConnectionInterface $conn The WebSocket connection
     * @return void
     */
    public function extractSessionData(ConnectionInterface $conn): void
    {
        // Extract HTTP headers and cookies from the connection request
        $cookies = [];
        $sessionId = null;
        $bookingSessionId = null;
        $userInfo = null;
        
        // Check if headers are available in the connection
        if (isset($conn->httpRequest) && $conn->httpRequest->hasHeader('Cookie')) {
            // Parse the cookie header
            $cookieHeader = $conn->httpRequest->getHeader('Cookie')[0];
            $this->logger->info("Cookies received", ['cookies' => $cookieHeader]);
            
            // Parse cookies into an associative array
            preg_match_all('/([^=;\s]+)\s*=\s*([^;\s]+)/', $cookieHeader, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $cookies[$match[1]] = urldecode($match[2]);
            }
            
            // Extract bookingfrontend session ID if available
            $bookingSessionId = $cookies['bookingfrontendsession'] ?? null;
            
            // Fall back to standard PHP session if booking session not found
            if (!$bookingSessionId) {
                $sessionId = $cookies['PHPSESSID'] ?? null;
            } else {
                $sessionId = $bookingSessionId;
            }
            
            // Try to get user information from the session
            if ($bookingSessionId) {
                $userInfo = $this->extractUserInfoFromSession($bookingSessionId);
            }
            
            // Extract User-Agent for browser information
            $userAgent = 'unknown';
            if (isset($conn->httpRequest) && $conn->httpRequest->hasHeader('User-Agent')) {
                $userAgent = $conn->httpRequest->getHeader('User-Agent')[0];
            }
            
            // Store cookies, session, and user-agent in connection for later use
            $conn->cookies = $cookies;
            $conn->sessionId = $sessionId;
            $conn->bookingSessionId = $bookingSessionId;
            $conn->userInfo = $userInfo;
            $conn->userAgent = $userAgent;
            
            // Flag this connection as requiring a session ID if none was found
            $conn->sessionIdRequired = !$sessionId;
            
            // Log the extracted data with limited session ID info for security
            $maskedSessionId = $sessionId ? substr($sessionId, 0, 8) . '...' : null;
            $maskedBookingSessionId = $bookingSessionId ? substr($bookingSessionId, 0, 8) . '...' : null;
            
            $this->logger->info("Session data extracted", [
                'sessionId' => $maskedSessionId,
                'bookingSessionId' => $maskedBookingSessionId,
                'hasBookingSession' => !empty($bookingSessionId),
                'userInfo' => $userInfo,
                'cookiesCount' => count($cookies),
                'userAgent' => $userAgent
            ]);
        } else {
            $this->logger->info("No cookies found in connection request");
        }
    }
    
    /**
     * Check if the session exists (simplified version without extracting user data)
     *
     * @param string $bookingSessionId Session ID
     * @return array Basic session info
     */
    private function extractUserInfoFromSession(string $bookingSessionId): array
    {
        // We're not actually extracting user data anymore - just returning 
        // basic session validation info without accessing the session
        return [
            'sessionFound' => true,
            'sessionId' => substr($bookingSessionId, 0, 8) . '****' // Masked session ID
        ];
    }
    
    /**
     * Enrich a message with basic session info
     *
     * @param ConnectionInterface $from Connection
     * @param array $data Message data
     * @return array Enriched message data
     */
    public function enrichMessageWithUserContext(ConnectionInterface $from, array $data): array
    {
        // Add basic session info to the message if available
        if (isset($from->sessionId)) {
            $messageType = $data['type'] ?? 'unknown';
            
            // Add session info to the message for broadcasting if appropriate
            if ($messageType !== 'ping' && $messageType !== 'pong') {
                $data['sessionContext'] = [
                    'hasSession' => true,
                    'sessionType' => isset($from->bookingSessionId) ? 'booking' : 'standard'
                ];
            }
        }
        
        return $data;
    }
    
    /**
     * Log basic session information for a message
     *
     * @param ConnectionInterface $from Connection
     * @param string $messageType Message type
     * @return array Log context array
     */
    public function getSessionLogContext(ConnectionInterface $from, string $messageType = 'unknown'): array
    {
        // Add session info to log context (simplified)
        $sessionContext = [];
        if (isset($from->sessionId)) {
            $sessionContext['hasSession'] = true;
            $sessionContext['sessionType'] = isset($from->bookingSessionId) ? 'booking' : 'standard';
        }
        
        return array_merge([
            'clientId' => $from->resourceId,
            'type' => $messageType,
        ], $sessionContext);
    }
    
    /**
     * Update the session ID for a connection
     * 
     * @param ConnectionInterface $conn The connection to update
     * @param string $sessionId The new session ID
     * @param \App\WebSocket\Services\RoomService $roomService Room service for room operations
     * @return array Result of the operation
     */
    public function updateSessionId(ConnectionInterface $conn, string $sessionId, RoomService $roomService): array
    {
        // Check if the new session ID is the same as the current one
        if (isset($conn->sessionId) && $conn->sessionId === $sessionId) {
            $this->logger->info("Session ID is unchanged", [
                'clientId' => $conn->resourceId,
                'sessionId' => substr($sessionId, 0, 8) . '...'
            ]);
            
            return [
                'success' => true,
                'action' => 'none',
                'message' => 'Session ID is unchanged'
            ];
        }
        
        // Store the old session info for logging and room management
        $oldSessionId = $conn->sessionId ?? null;
        $oldRoomId = $oldSessionId ? $roomService->createRoomIdFromSession($oldSessionId) : null;
        
        // Update the connection with the new session ID
        $conn->sessionId = $sessionId;
        $conn->bookingSessionId = $sessionId; // Treat all explicit updates as booking sessions
        
        // Clear the session required flag if it was set
        $conn->sessionIdRequired = false;
        
        // Create a room ID for the new session
        $newRoomId = $roomService->createRoomIdFromSession($sessionId);
        
        // If the connection was in a session room, remove it
        if ($oldRoomId && $roomService->isInRoom($oldRoomId, $conn)) {
            $roomService->leaveRoom($oldRoomId, $conn);
            $this->logger->info("Removed from old session room", [
                'clientId' => $conn->resourceId,
                'oldRoom' => $oldRoomId
            ]);
        }
        
        // Add to the new session room
        $roomService->joinRoom($newRoomId, $conn);
        $conn->roomId = $newRoomId;
        
        $this->logger->info("Session ID updated", [
            'clientId' => $conn->resourceId,
            'oldSessionId' => $oldSessionId ? substr($oldSessionId, 0, 8) . '...' : 'none',
            'newSessionId' => substr($sessionId, 0, 8) . '...',
            'newRoom' => $newRoomId
        ]);
        
        // Extract user info for the new session
        $conn->userInfo = $this->extractUserInfoFromSession($sessionId);
        
        return [
            'success' => true,
            'action' => $oldSessionId ? 'updated' : 'set',
            'message' => $oldSessionId ? 'Session ID updated' : 'Session ID set',
            'roomId' => $newRoomId
        ];
    }
}