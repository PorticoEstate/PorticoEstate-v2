<?php
// Ultra-simple socket server for testing port binding

$logFile = '/var/log/apache2/raw_socket.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Starting raw socket test\n");

// Create a raw socket server on port 8080
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

// Log any errors
if (!$socket) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Error creating socket: " . socket_strerror(socket_last_error()) . "\n", FILE_APPEND);
    exit(1);
}

// Set option to reuse address
if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Error setting socket option: " . socket_strerror(socket_last_error($socket)) . "\n", FILE_APPEND);
    socket_close($socket);
    exit(1);
}

// Try to bind to port 8080 on all interfaces
if (!socket_bind($socket, '0.0.0.0', 8080)) {
    $errorCode = socket_last_error($socket);
    $errorMsg = socket_strerror($errorCode);
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Error binding socket: ($errorCode) $errorMsg\n", FILE_APPEND);
    
    // If port in use, try another port
    if ($errorCode === 98) { // Address already in use
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Port 8080 is already in use. Trying port 8081...\n", FILE_APPEND);
        
        if (!socket_bind($socket, '0.0.0.0', 8081)) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - Error binding to alternate port: " . socket_strerror(socket_last_error($socket)) . "\n", FILE_APPEND);
            socket_close($socket);
            exit(1);
        }
        
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Successfully bound to port 8081\n", FILE_APPEND);
        echo "Listening on port 8081\n";
    } else {
        socket_close($socket);
        exit(1);
    }
} else {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Successfully bound to port 8080\n", FILE_APPEND);
    echo "Listening on port 8080\n";
}

// Start listening for connections
if (!socket_listen($socket, 5)) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Error listening on socket: " . socket_strerror(socket_last_error($socket)) . "\n", FILE_APPEND);
    socket_close($socket);
    exit(1);
}

file_put_contents($logFile, date('Y-m-d H:i:s') . " - Socket server started and listening\n", FILE_APPEND);

// Keep socket open for 30 seconds
echo "Socket will remain open for 30 seconds...\n";
sleep(30);

socket_close($socket);
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Socket closed\n", FILE_APPEND);
echo "Socket closed\n";