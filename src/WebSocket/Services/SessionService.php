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
     * Extract user information from the bookingfrontend session
     *
     * @param string $bookingSessionId Session ID
     * @return array|null User info if found
     */
    private function extractUserInfoFromSession(string $bookingSessionId): ?array
    {
        try {
            // Start PHP session with the provided session ID
            if (session_status() === PHP_SESSION_NONE) {
                session_id($bookingSessionId);
                session_name('bookingfrontendsession');
                session_start();
                
                // Check if the session contains user data
                if (isset($_SESSION['phpgw_cache'])) {
                    // Try to extract organization info
                    $orgId = null;
                    $orgnr = null;
                    
                    // Generate cache keys manually since we can't access protected methods
                    $orgIdKey = 'phpgw_bookingfrontend_org_id';
                    $orgnrKey = 'phpgw_bookingfrontend_orgnr';
                    $userArrayKey = 'phpgw_bookingfrontend_userarray';
                    
                    if (isset($_SESSION['phpgw_cache'][$orgIdKey])) {
                        $orgId = $_SESSION['phpgw_cache'][$orgIdKey];
                        // We can't easily decrypt values without the Cache class helper methods,
                        // so we'll just store the raw values for now
                    }
                    
                    if (isset($_SESSION['phpgw_cache'][$orgnrKey])) {
                        $orgnr = $_SESSION['phpgw_cache'][$orgnrKey];
                        // Raw value without decryption
                    }
                    
                    // Try to get user data array
                    if (isset($_SESSION['phpgw_cache'][$userArrayKey])) {
                        $userData = $_SESSION['phpgw_cache'][$userArrayKey];
                        // Raw value without decryption
                        
                        // If userData is already an array (shouldn't happen without decryption)
                        if (is_array($userData) && isset($userData['ssn'])) {
                            $userInfo = [
                                'ssn' => substr($userData['ssn'], 0, 6) . '****', // Mask the SSN for logging
                                'hasSSN' => true,
                                'orgId' => $orgId,
                                'orgnr' => $orgnr,
                            ];
                        } else {
                            // Since we can't decrypt, we'll just note that session data was found
                            $userInfo = [
                                'sessionFound' => true,
                                'hasRawOrgId' => !empty($orgId),
                                'hasRawOrgnr' => !empty($orgnr),
                                'hasRawUserData' => !empty($userData)
                            ];
                        }
                        
                        // Close the session to not interfere with other processes
                        session_write_close();
                        
                        return $userInfo;
                    }
                }
                
                // Close the session to not interfere with other processes
                session_write_close();
            }
        } catch (\Exception $e) {
            $this->logger->error("Error extracting user info from session", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
        
        return null;
    }
    
    /**
     * Enrich a message with user context
     *
     * @param ConnectionInterface $from Connection
     * @param array $data Message data
     * @return array Enriched message data
     */
    public function enrichMessageWithUserContext(ConnectionInterface $from, array $data): array
    {
        // Add user context to the message if available
        if (isset($from->userInfo)) {
            $messageType = $data['type'] ?? 'unknown';
            
            // Add user context to the message for broadcasting if appropriate
            if ($messageType !== 'ping' && $messageType !== 'pong') {
                $data['userContext'] = [
                    'authenticated' => true,
                    'hasSession' => true
                ];
                
                // If we have organization info, include it
                if (isset($from->userInfo['orgnr'])) {
                    $data['userContext']['orgnr'] = $from->userInfo['orgnr'];
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Log user session information for a message
     *
     * @param ConnectionInterface $from Connection
     * @param string $messageType Message type
     * @return array Log context array
     */
    public function getSessionLogContext(ConnectionInterface $from, string $messageType = 'unknown'): array
    {
        // Build user context for logging
        $userContext = [];
        if (isset($from->userInfo)) {
            $userContext['userInfo'] = $from->userInfo;
        }
        
        // Add session info to log context
        $sessionContext = [];
        if (isset($from->sessionId)) {
            $sessionContext['hasSession'] = true;
            $sessionContext['sessionType'] = isset($from->bookingSessionId) ? 'booking' : 'standard';
        }
        
        return array_merge([
            'clientId' => $from->resourceId,
            'type' => $messageType,
        ], $sessionContext, $userContext);
    }
}