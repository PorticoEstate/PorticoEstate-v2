<?php

namespace App\WebSocket;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\HttpServer;
use App\WebSocket\WebSocketServer;

/**
 * HTTP routes for WebSocket server
 */
class Routes
{
    private $webSocketServer;
    
    /**
     * Constructor
     * 
     * @param WebSocketServer $webSocketServer The WebSocket server instance
     */
    public function __construct(WebSocketServer $webSocketServer)
    {
        $this->webSocketServer = $webSocketServer;
    }
    
    /**
     * Create HTTP server with routes
     * 
     * @param \React\EventLoop\LoopInterface $loop Event loop
     * @return HttpServer
     */
    public function createServer($loop)
    {
        return new HttpServer(
            $loop,
            function (ServerRequestInterface $request) {
                $path = $request->getUri()->getPath();
                
                // Route for publishing messages to WebSocket clients
                if ($path === '/wss-publish' && $request->getMethod() === 'POST') {
                    return $this->handlePublish($request);
                }
                
                // Health check route
                if ($path === '/health' || $path === '/wss/health') {
                    return $this->handleHealthCheck();
                }
                
                // Return 404 for any other routes
                return new Response(
                    404,
                    ['Content-Type' => 'text/plain'],
                    'Not found'
                );
            }
        );
    }
    
    /**
     * Handle publish request
     * 
     * @param ServerRequestInterface $request
     * @return Response
     */
    private function handlePublish(ServerRequestInterface $request)
    {
        // Get the body content
        $body = (string) $request->getBody();
        
        // Log the request
        error_log("WebSocket Routes: Received publish request: " . substr($body, 0, 200));
        
        try {
            // Parse the JSON
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new Response(
                    400,
                    ['Content-Type' => 'application/json'],
                    json_encode(['success' => false, 'error' => 'Invalid JSON'])
                );
            }
            
            // Broadcast the message to all clients
            $this->webSocketServer->broadcastNotification($body);
            
            // Return success response
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['success' => true, 'message' => 'Message broadcasted successfully'])
            );
        } catch (\Exception $e) {
            error_log("WebSocket Routes: Error handling publish request: " . $e->getMessage());
            
            // Return error response
            return new Response(
                500,
                ['Content-Type' => 'application/json'],
                json_encode(['success' => false, 'error' => $e->getMessage()])
            );
        }
    }
    
    /**
     * Handle health check request
     * 
     * @return Response
     */
    private function handleHealthCheck()
    {
        $clientCount = $this->webSocketServer->getClientCount();
        
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'status' => 'ok',
                'clients' => $clientCount,
                'timestamp' => date('c')
            ])
        );
    }
}