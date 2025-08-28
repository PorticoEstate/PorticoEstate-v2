<?php

namespace App\providers;

use DI\Container;
use Psr\Log\LoggerInterface;
use App\WebSocket\WebSocketServer;
use App\WebSocket\SimpleLogger;

class WebSocketServiceProvider
{
    /**
     * Register WebSocket services with the container
     *
     * @param Container $container DI container
     * @return void
     */
    public function register(Container $container): void
    {
        // Register the logger for WebSocket server
        if (!$container->has(LoggerInterface::class)) {
            $container->set(LoggerInterface::class, function() {
                return new SimpleLogger('/var/log/apache2/websocket.log');
            });
        }
        
        // Register the WebSocketServer
        $container->set(WebSocketServer::class, function (Container $c) {
            return new WebSocketServer($c->get(LoggerInterface::class));
        });
    }
}