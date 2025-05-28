<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\WebSocketHelper;
use App\modules\phpgwapi\security\Sessions;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DebugController
{
    /**
     * Trigger a partial applications update for debugging
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function triggerPartialUpdate(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $sessionId = $data['sessionId'] ?? null;
            
            if (!$sessionId) {
                // Try to get from current session
                $session = Sessions::getInstance();
                $sessionId = $session->get_session_id();
            }
            
            if (!$sessionId) {
                return ResponseHelper::sendJSONResponse([
                    'success' => false,
                    'message' => 'No session ID provided'
                ], 400);
            }
            
            error_log("DebugController: Triggering partial applications update for session: " . substr($sessionId, 0, 8) . "...");
            
            // Trigger the update
            $success = WebSocketHelper::triggerPartialApplicationsUpdate($sessionId);
            
            error_log("DebugController: Trigger result: " . ($success ? 'success' : 'failure'));
            
            return ResponseHelper::sendJSONResponse([
                'success' => $success,
                'message' => $success ? 'Partial applications update triggered' : 'Failed to trigger update',
                'sessionId' => substr($sessionId, 0, 8) . '...',
                'timestamp' => date('c')
            ]);
            
        } catch (\Exception $e) {
            error_log("DebugController error: " . $e->getMessage());
            return ResponseHelper::sendJSONResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Test Redis connection and channels
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function testRedis(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $channel = $data['channel'] ?? 'session_messages';
            $message = $data['message'] ?? ['type' => 'test', 'timestamp' => date('c')];
            
            error_log("DebugController: Testing Redis publish to channel: " . $channel);
            
            // Test direct Redis publish
            $success = WebSocketHelper::sendRedisNotification($message, $channel);
            
            error_log("DebugController: Redis publish result: " . ($success ? 'success' : 'failure'));
            
            return ResponseHelper::sendJSONResponse([
                'success' => $success,
                'message' => $success ? 'Redis message sent' : 'Failed to send Redis message',
                'channel' => $channel,
                'messageType' => $message['type'] ?? 'unknown',
                'timestamp' => date('c')
            ]);
            
        } catch (\Exception $e) {
            error_log("DebugController Redis test error: " . $e->getMessage());
            return ResponseHelper::sendJSONResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get current session information
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function getSessionInfo(Request $request, Response $response): Response
    {
        try {
            $session = Sessions::getInstance();
            $sessionId = $session->get_session_id();
            
            $sessionData = [
                'sessionId' => $sessionId,
                'sessionIdMasked' => $sessionId ? substr($sessionId, 0, 8) . '...' : null,
                'cookies' => $_COOKIE,
                'headers' => getallheaders(),
                'environment' => [
                    'REDIS_HOST' => getenv('REDIS_HOST'),
                    'WEBSOCKET_HOST' => getenv('websocket_host') ?: getenv('WEBSOCKET_HOST'),
                    'SLIM_HOST' => getenv('SLIM_HOST'),
                    'NEXTJS_HOST' => getenv('NEXTJS_HOST')
                ],
                'timestamp' => date('c')
            ];
            
            return ResponseHelper::sendJSONResponse($sessionData);
            
        } catch (\Exception $e) {
            error_log("DebugController session info error: " . $e->getMessage());
            return ResponseHelper::sendJSONResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}