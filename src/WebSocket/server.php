<?php

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\WebSocketServer;
use App\WebSocket\Services\RedisService;
use React\EventLoop\Factory;
use React\Socket\SocketServer;
use Psr\Log\LoggerInterface;

// Set custom error handling to capture any issues
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/websocket_error.log');
// Suppress deprecation warnings which are common with Ratchet on PHP 8.4+
error_reporting(E_ALL & ~E_DEPRECATED);

// Disable xdebug in production mode
if (extension_loaded('xdebug')) {
    ini_set('xdebug.remote_enable', 0);
    ini_set('xdebug.remote_autostart', 0);
    ini_set('xdebug.remote_connect_back', 0);
    ini_set('xdebug.idekey', '');
    
    // For Xdebug 3
    ini_set('xdebug.mode', 'off');
    ini_set('xdebug.start_with_request', 'no');
    
    // More aggressive disabling
    if (function_exists('xdebug_disable')) {
        xdebug_disable();
    }
}

// Make sure we have Composer autoload
$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die("Composer autoload not found at: {$autoloadPath}. Please run composer install.");
}
require $autoloadPath;

// WebSocket server configuration constants
define('WSS_LOG_ENABLED', true);           // Master switch for all logging
define('WSS_DEBUG_LOG_ENABLED', false);    // Enable for detailed debug logs
define('WSS_LOG_TO_DOCKER', true);         // Enable for Docker log integration

// Create a custom logger that outputs to stdout
$logger = new class implements LoggerInterface {
    public function emergency($message, array $context = []): void { $this->log('EMERGENCY', $message, $context); }
    public function alert($message, array $context = []): void { $this->log('ALERT', $message, $context); }
    public function critical($message, array $context = []): void { $this->log('CRITICAL', $message, $context); }
    public function error($message, array $context = []): void { $this->log('ERROR', $message, $context); }
    public function warning($message, array $context = []): void { $this->log('WARNING', $message, $context); }
    public function notice($message, array $context = []): void { $this->log('NOTICE', $message, $context); }
    public function info($message, array $context = []): void { $this->log('INFO', $message, $context); }
    public function debug($message, array $context = []): void { 
        // Only log debug messages if debug logging is enabled
        if (WSS_DEBUG_LOG_ENABLED) {
            $this->log('DEBUG', $message, $context); 
        }
    }
    public function log($level, $message, array $context = []): void {
        // Skip logging if disabled
        if (!WSS_LOG_ENABLED) {
            return;
        }
        
        // Add timestamp, level, and process ID to the log format
        $pid = getmypid();
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        
        // Output to stdout (which can be redirected by supervisord)
        echo "[{$timestamp}] [{$level}] [PID:{$pid}] {$message}{$contextStr}" . PHP_EOL;
    }
};

// Create EventLoop
$loop = Factory::create();

// Set up our WebSocket server for WebSocket connections
$webSocket = new WebSocketServer($logger);

// Configure WsServer to pass the original HTTP request to the WebSocket server
// This enables access to cookies and headers
$wsServer = new WsServer($webSocket);

// Enable protocol-level WebSocket ping/pong frames for better connection management
$wsServer->enableKeepAlive($loop, 30); // Send protocol-level ping every 30 seconds

// Make sure httpRequest is available to the server
$wsHttpServer = new HttpServer(
    // HttpServer should pass the original request to the WsServer
    // This ensures cookies are accessible
    $wsServer
);

// Note: Separate HTTP server for API endpoints is disabled due to missing React HTTP component
// We'll use the broadcast method directly in WebSocketServer class instead

// Listen on all interfaces on port 8080
try {
    // More verbose binding with debug information
    echo "Creating socket server on 0.0.0.0:8080..." . PHP_EOL;
    
    // Explicitly check if port is already in use (Linux only)
    if (function_exists('socket_create') && function_exists('socket_bind')) {
        $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $canBind = socket_bind($testSocket, '0.0.0.0', 8080);
        socket_close($testSocket);
        
        if (!$canBind) {
            echo "WARNING: Port 8080 appears to be already in use!" . PHP_EOL;
            echo "Error code: " . socket_last_error() . " - " . socket_strerror(socket_last_error()) . PHP_EOL;
        } else {
            echo "Port 8080 is available for binding." . PHP_EOL;
        }
    }
    
    // Create the socket server with React
    $socketAddress = '0.0.0.0:8080';
    echo "Binding to $socketAddress..." . PHP_EOL;
    
    // Show environment information
    echo "PHP version: " . PHP_VERSION . PHP_EOL;
    echo "Operating system: " . PHP_OS . PHP_EOL;
    echo "Server software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . PHP_EOL;

    // Modify the logger to also write important messages to STDERR for Docker logs if enabled
    if (WSS_LOG_TO_DOCKER) {
        $originalLogger = $logger;
        $logger = new class($originalLogger) implements LoggerInterface {
            private $innerLogger;
            
            public function __construct(LoggerInterface $innerLogger) {
                $this->innerLogger = $innerLogger;
            }
            
            public function emergency($message, array $context = []): void { $this->log('EMERGENCY', $message, $context); }
            public function alert($message, array $context = []): void { $this->log('ALERT', $message, $context); }
            public function critical($message, array $context = []): void { $this->log('CRITICAL', $message, $context); }
            public function error($message, array $context = []): void { $this->log('ERROR', $message, $context); }
            public function warning($message, array $context = []): void { $this->log('WARNING', $message, $context); }
            public function notice($message, array $context = []): void { $this->log('NOTICE', $message, $context); }
            public function info($message, array $context = []): void { $this->log('INFO', $message, $context); }
            public function debug($message, array $context = []): void { 
                // Only forward debug messages if debug logging is enabled
                if (WSS_DEBUG_LOG_ENABLED) {
                    $this->log('DEBUG', $message, $context);
                }
            }
            
            public function log($level, $message, array $context = []): void {
                // Forward to inner logger
                $this->innerLogger->log($level, $message, $context);
                
                // Only write errors and warnings to stderr for Docker logs
                if (in_array($level, ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING'])) {
                    $formattedContext = !empty($context) ? ' ' . json_encode($context) : '';
                    $dockerMessage = "[" . date('Y-m-d H:i:s') . "] [{$level}] WSS: {$message}{$formattedContext}" . PHP_EOL;
                    
                    // Write to stderr (appears in Docker logs)
                    fwrite(STDERR, $dockerMessage);
                }
            }
        };
    }
    
    // Create WebSocket server on port 8080
    $context = [];
    $socket = new SocketServer($socketAddress, $context, $loop);
    $server = new IoServer($wsHttpServer, $socket, $loop);
    
    echo "WebSocket server listening on port 8080" . PHP_EOL;

    echo "SUCCESS: WebSocket server started on 0.0.0.0:8080" . PHP_EOL;
    
    // Write a status file that can be checked
    file_put_contents('/tmp/websocket_running', date('Y-m-d H:i:s'));
    
    // Set up a timer to check for notification files every second (as fallback)
    $loop->addPeriodicTimer(1, function() use ($webSocket) {
        $webSocket->checkNotificationFiles();
    });
    
    // Send server ping to all clients every 240 seconds (4 minutes) to keep connections alive
    $loop->addPeriodicTimer(240, function() use ($webSocket) {
        $webSocket->sendServerPing();
    });
    
    // Send ping to all entity rooms every 240 seconds (4 minutes)
    // This helps detect inactive clients and clean them up
    $loop->addPeriodicTimer(240, function() use ($webSocket, $logger) {
        $rooms = $webSocket->getRooms();
        $entityRooms = array_filter($rooms, function($room) {
            return !$room['isSessionRoom'] && strpos($room['id'], 'entity_') === 0;
        });
        
        if (count($entityRooms) > 0) {
            $logger->info("Pinging entity rooms", [
                'roomCount' => count($entityRooms)
            ]);
            
            foreach ($entityRooms as $room) {
                $webSocket->getRoomService()->pingRoom($room['id']);
            }
        }
    });
    
    // Clean up inactive room connections every 480 seconds (8 minutes)
    $loop->addPeriodicTimer(480, function() use ($webSocket, $logger) {
        $logger->info("Cleaning up inactive room connections");
        $stats = $webSocket->getRoomService()->cleanupInactiveConnections(480); // 8 minutes threshold
        
        if ($stats['connectionsRemoved'] > 0) {
            $logger->info("Inactive connections cleanup complete", [
                'connectionsRemoved' => $stats['connectionsRemoved'],
                'roomsCleaned' => count($stats['roomsCleaned'])
            ]);
        }
    });
    
    // Set up Redis Pub/Sub if Redis is available
    if ($webSocket->isRedisEnabled()) {
        try {
            // Use Clue Redis React for non-blocking Redis pub/sub
            $factory = new \Clue\React\Redis\Factory($loop);
            $redisClient = null;
            
            $factory->createClient('redis://' . (getenv('REDIS_HOST') ?: 'redis') . ':' . (getenv('REDIS_PORT') ?: 6379))
                ->then(function (\Clue\React\Redis\Client $client) use ($webSocket, $logger, &$redisClient) {
                    $redisClient = $client;
                    $logger->info("Redis pub/sub connection established");
                    
                    // Subscribe to general notifications channel
                    $client->subscribe('notifications');
                    
                    // Subscribe to session messages channel
                    $client->subscribe('session_messages');
                    
                    // Subscribe to room messages channel for entity room messages
                    $client->subscribe('room_messages');
                    
                    // Handle messages
                    $client->on('message', function ($channel, $payload) use ($webSocket, $logger) {
                        // Parse the payload for logging and processing
                        $data = json_decode($payload, true);
                        $messageType = $data['type'] ?? 'unknown';
                        
                        // Handle room_messages channel
                        if ($channel === 'room_messages') {
                            if ($messageType === 'room_message' && isset($data['roomId'])) {
                                $roomId = $data['roomId'];
                                
                                $logger->info("Room message received via Redis", [
                                    'roomId' => $roomId,
                                    'type' => $messageType,
                                    'timestamp' => date('c')
                                ]);
                                
                                // Send message to the specific room
                                if ($webSocket->getRoomService()->roomExists($roomId)) {
                                    $sent = $webSocket->getRoomService()->sendToRoom($roomId, $payload);
                                    
                                    $logger->info("Room message delivery", [
                                        'success' => $sent > 0,
                                        'roomId' => $roomId,
                                        'recipients' => $sent
                                    ]);
                                } else {
                                    $logger->warning("Room not found for message", [
                                        'roomId' => $roomId
                                    ]);
                                }
                                
                                return;
                            }
                        }
                        
                        if ($channel === 'notifications') {
                            // Handle entity-targeted notifications (legacy format)
                            if (isset($data['target']) && $data['target'] === 'entity' && 
                                isset($data['entityType']) && isset($data['entityId'])) {
                                // Get entity info
                                $entityType = $data['entityType'];
                                $entityId = $data['entityId'];
                                
                                $logger->info("Entity-targeted Redis notification received (legacy format)", [
                                    'entityType' => $entityType,
                                    'entityId' => $entityId,
                                    'type' => $messageType,
                                    'timestamp' => date('c')
                                ]);
                                
                                // Send to entity room
                                $success = $webSocket->sendToEntityRoom($entityType, $entityId, $payload);
                                
                                $logger->info("Entity message delivery", [
                                    'success' => $success,
                                    'entityType' => $entityType,
                                    'entityId' => $entityId
                                ]);
                                
                                return;
                            }
                            
                            // Handle session-targeted notifications
                            else if (isset($data['target']) && $data['target'] === 'session' && 
                                isset($data['sessionId'])) {
                                // Get session info
                                $sessionId = $data['sessionId'];
                                
                                $logger->info("Session-targeted Redis notification received", [
                                    'sessionId' => substr($sessionId, 0, 8) . '...',
                                    'type' => $messageType,
                                    'timestamp' => date('c')
                                ]);
                                
                                // Send to session room
                                $success = $webSocket->sendToSessionRoom($sessionId, $payload);
                                
                                $logger->info("Session message delivery", [
                                    'success' => $success,
                                    'sessionId' => substr($sessionId, 0, 8) . '...'
                                ]);
                                
                                return;
                            }
                            
                            // Handle general notifications
                            $notificationType = $data['data']['type'] ?? 'general';
                            $message = $data['message'] ?? 'No message';
                            
                            // Log detailed information
                            $logger->info("Redis notification received", [
                                'channel' => $channel,
                                'type' => $messageType,
                                'notificationType' => $notificationType,
                                'message' => $message,
                                'payload_length' => strlen($payload),
                                'timestamp' => date('c')
                            ]);
                            
                            // Add source information
                            if (is_array($data)) {
                                $data['source'] = 'redis';
                                $data['received_at'] = date('c');
                                $payload = json_encode($data);
                            }
                            
                            // Log client count and broadcast
                            $clientCount = $webSocket->getClientCount();
                            $logger->info("Broadcasting Redis message to {$clientCount} clients");
                            
                            // Broadcast to all clients
                            $webSocket->broadcastNotification($payload);
                            
                        } elseif ($channel === 'session_messages') {
                            // Handle session-targeted messages
                            if ($messageType === 'session_targeted' && isset($data['sessionId'])) {
                                $sessionId = $data['sessionId'];
                                $message = $data['message'] ?? 'No message';
                                
                                // Simplify logging to focus on most important information
                                $logger->info("Redis session message received", [
                                    'sessionId' => substr($sessionId, 0, 8) . '...',
                                    'type' => $data['data']['type'] ?? $messageType,
                                    'message_count' => isset($data['data']['messages']) ? count($data['data']['messages']) : 0,
                                    'timestamp' => date('c')
                                ]);
                                
                                // Send to specific session room
                                // Pass data directly to avoid additional nesting
                                $payload = json_encode($data['data'] ?? $data);
                                $success = $webSocket->sendToSessionRoom($sessionId, $payload);
                                
                                $logger->info("Session message delivery", [
                                    'success' => $success,
                                    'sessionId' => substr($sessionId, 0, 8) . '...'
                                ]);
                            } else {
                                $logger->warning("Invalid session message format", [
                                    'type' => $messageType,
                                    'hasSessionId' => isset($data['sessionId'])
                                ]);
                            }
                        }
                    });
                }, function (\Exception $e) use ($logger) {
                    $logger->error("Redis pub/sub connection failed", [
                        'error' => $e->getMessage()
                    ]);
                });
        } catch (\Exception $e) {
            $logger->error("Redis pub/sub setup failed", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // Check for session-specific notification files
    $loop->addPeriodicTimer(2, function() use ($webSocket, $logger) {
        // Look for session message files
        $files = glob('/tmp/websocket_session_*.json');
        
        if (!empty($files)) {
            $logger->info("Processing session message files", [
                'count' => count($files)
            ]);
            
            foreach ($files as $file) {
                try {
                    $content = file_get_contents($file);
                    
                    if ($content) {
                        $data = json_decode($content, true);
                        
                        if (isset($data['sessionId'])) {
                            $sessionId = $data['sessionId'];
                            
                            $logger->info("Processing session message file", [
                                'file' => basename($file),
                                'sessionId' => substr($sessionId, 0, 8) . '...'
                            ]);
                            
                            // Send to specific session room
                            $webSocket->sendToSessionRoom($sessionId, $data);
                        }
                        
                        // Delete the file after processing
                        unlink($file);
                    }
                } catch (\Exception $e) {
                    $logger->error("Session file processing error", [
                        'file' => basename($file),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    });
    
    // Display log configuration status
    if (WSS_LOG_ENABLED) {
        echo "[" . date('Y-m-d H:i:s') . "] [INFO] Logging is ENABLED" . PHP_EOL;
        echo "[" . date('Y-m-d H:i:s') . "] [INFO] Debug logging is " . (WSS_DEBUG_LOG_ENABLED ? "ENABLED" : "DISABLED") . PHP_EOL;
        echo "[" . date('Y-m-d H:i:s') . "] [INFO] Docker log integration is " . (WSS_LOG_TO_DOCKER ? "ENABLED" : "DISABLED") . PHP_EOL;
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] [INFO] Logging is DISABLED" . PHP_EOL;
    }
} catch (\Exception $e) {
    echo "ERROR starting WebSocket server: " . $e->getMessage() . PHP_EOL;
    echo "Stack trace: " . $e->getTraceAsString() . PHP_EOL;
    
    // Try alternate port if 8080 is occupied
    try {
        echo "Trying alternate ports..." . PHP_EOL;
        // WebSocket on 8082
        $wsSocket = new SocketServer('0.0.0.0:8082', [], $loop);
        $wsServer = new IoServer($wsHttpServer, $wsSocket, $loop);
        
        // HTTP API on 8083
        $httpSocket = new SocketServer('0.0.0.0:8083', [], $loop);
        IoServer::factory($httpServer, $httpSocket);
        
        echo "WebSocket server listening on port 8082" . PHP_EOL;
        echo "HTTP API server listening on port 8083" . PHP_EOL;
        
        // Update Apache config to use alternate port
        $apacheConfig = '/etc/apache2/sites-enabled/000-default.conf';
        if (file_exists($apacheConfig)) {
            $config = file_get_contents($apacheConfig);
            $config = str_replace('ws://slim:8080', 'ws://slim:8082', $config);
            file_put_contents($apacheConfig, $config);
            echo "Updated Apache config to use port 8082" . PHP_EOL;
            
            // Also update the WebSocketHelper class if we can find it
            $wsHelper = '/var/www/html/src/modules/bookingfrontend/helpers/WebSocketHelper.php';
            if (file_exists($wsHelper)) {
                $helperContent = file_get_contents($wsHelper);
                $helperContent = str_replace('protected static $port = 8081', 'protected static $port = 8083', $helperContent);
                file_put_contents($wsHelper, $helperContent);
                echo "Updated WebSocketHelper to use port 8083" . PHP_EOL;
            }
        }
        
        echo "SUCCESS: WebSocket server started on 0.0.0.0:8081" . PHP_EOL;
        file_put_contents('/tmp/websocket_running', date('Y-m-d H:i:s') . ' (port 8081)');
    } catch (\Exception $e2) {
        echo "FATAL ERROR starting WebSocket server on alternate port: " . $e2->getMessage() . PHP_EOL;
        echo "Stack trace: " . $e2->getTraceAsString() . PHP_EOL;
        exit(1);
    }
}

$loop->run();