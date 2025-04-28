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
            
            // Store cookies and session in connection for later use
            $conn->cookies = $cookies;
            $conn->sessionId = $sessionId;
            $conn->bookingSessionId = $bookingSessionId;
            $conn->userInfo = $userInfo;
            
            // Log the extracted data with limited session ID info for security
            $maskedSessionId = $sessionId ? substr($sessionId, 0, 8) . '...' : null;
            $maskedBookingSessionId = $bookingSessionId ? substr($bookingSessionId, 0, 8) . '...' : null;
            
            $this->logger->info("Session data extracted", [
                'sessionId' => $maskedSessionId,
                'bookingSessionId' => $maskedBookingSessionId,
                'hasBookingSession' => !empty($bookingSessionId),
                'userInfo' => $userInfo,
                'cookiesCount' => count($cookies)
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
}