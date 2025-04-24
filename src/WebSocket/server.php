<?php

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\WebSocketServer;
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

// Create a custom logger
$logger = new class implements LoggerInterface {
    public function emergency($message, array $context = []): void { $this->log('EMERGENCY', $message, $context); }
    public function alert($message, array $context = []): void { $this->log('ALERT', $message, $context); }
    public function critical($message, array $context = []): void { $this->log('CRITICAL', $message, $context); }
    public function error($message, array $context = []): void { $this->log('ERROR', $message, $context); }
    public function warning($message, array $context = []): void { $this->log('WARNING', $message, $context); }
    public function notice($message, array $context = []): void { $this->log('NOTICE', $message, $context); }
    public function info($message, array $context = []): void { $this->log('INFO', $message, $context); }
    public function debug($message, array $context = []): void { $this->log('DEBUG', $message, $context); }
    public function log($level, $message, array $context = []): void {
        echo "[" . date('Y-m-d H:i:s') . "] [{$level}] {$message}" . PHP_EOL;
    }
};

// Create EventLoop
$loop = Factory::create();

// Set up our WebSocket server for WebSocket connections
$webSocket = new WebSocketServer($logger);
$wsServer = new WsServer($webSocket);
$wsHttpServer = new HttpServer($wsServer);

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
    
    // Create WebSocket server on port 8080
    $context = [];
    $socket = new SocketServer($socketAddress, $context, $loop);
    $server = new IoServer($wsHttpServer, $socket, $loop);
    
    echo "WebSocket server listening on port 8080" . PHP_EOL;

    echo "SUCCESS: WebSocket server started on 0.0.0.0:8080" . PHP_EOL;
    
    // Write a status file that can be checked
    file_put_contents('/tmp/websocket_running', date('Y-m-d H:i:s'));
    
    // Set up a timer to check for notification files every second
    $loop->addPeriodicTimer(1, function() use ($webSocket) {
        $webSocket->checkNotificationFiles();
    });
    
    // Send server ping to all clients every 55 seconds to keep connections alive
    $loop->addPeriodicTimer(55, function() use ($webSocket) {
        $webSocket->sendServerPing();
    });
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